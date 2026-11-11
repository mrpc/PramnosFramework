<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Llm\AddCatalogueColumnsToChatSkills;
use Pramnos\Framework\Migrations\Llm\CreateChatSkillsTable;
use Pramnos\Llm\Chat\CatalogSkills;
use Pramnos\Llm\Chat\SkillCatalog;
use Pramnos\Llm\Chat\Skills;
use Pramnos\Llm\Chat\StoredSkills;
use Pramnos\User\User;

/**
 * Skills installed from a catalogue for the whole application, kept in the database — MySQL here,
 * PostgreSQL in the sibling.
 *
 * Why it matters: a skill is instructions to a model that reaches the application's data, written
 * by somebody outside it. So it is read before it is installed, installed off, on only once a
 * person who manages skills turns it on — recorded with the commit they read — never brought up
 * to date by itself, off again when its text changes, and never a script. It lives in the database
 * so that a deploy does not take it away, and a skill file of the repository wins over it.
 */
#[CoversClass(CatalogSkills::class)]
#[CoversClass(AddCatalogueColumnsToChatSkills::class)]
class CatalogSkillsMySQLTest extends TestCase
{
    protected Database $db;

    /** @var array<string, mixed> */
    private array $info = [];

    /** @var array<string, User> The people the tests act as, by role */
    private array $people = [];

    /** A catalogue that answers from memory: acme/skills at commit a…, then b…. */
    private MemoryCatalog $catalog;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        User::setupDb();
        // The audit log too: who installed and turned on which commit is recorded there.
        \Pramnos\Framework\Testing\Schema::ensure([CreateChatSkillsTable::class, AddCatalogueColumnsToChatSkills::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class], $this->db);
        \Pramnos\Auth\AuditLog::resetTableCache();
        $this->db->queryBuilder()->table(StoredSkills::TABLE)->where('level', CatalogSkills::LEVEL)->delete();
        // The audit of earlier runs: chat_skills is made again, its ids start over, and an old
        // row of the same skill id would be read as this test's.
        $this->db->queryBuilder()->table('authserver.audit_log')->where('object_type', 'chat_skill')->delete();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['llm'] = ['chat' => ['skill_catalogues' => ['acme/skills', 'gone/repo']]];
        foreach (['admin' => 90, 'staff' => 10] as $role => $type) {
            $this->people[$role] = $this->person($role, $type);
        }
        $this->catalog = new MemoryCatalog();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table(StoredSkills::TABLE)->where('level', CatalogSkills::LEVEL)->delete();
        $ids = array_map(static fn (User $u): int => (int) $u->userid, $this->people);
        if ($ids !== []) {
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $ids)->delete();
        }
        User::clearUserCache();
        Application::getInstance()->applicationInfo = $this->info;
        \Pramnos\Auth\AuditLog::resetTableCache();
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }

        return $db;
    }

    /** A saved account of a usertype. */
    private function person(string $role, int $type): User
    {
        $name = 'catalog_' . $role . '_' . bin2hex(random_bytes(3));
        $user = new User();
        $user->username = $name;
        $user->email    = $name . '@example.com';
        $user->usertype = $type;
        $user->save();
        User::clearUserCache();

        return $user;
    }

    /** The store under test, on the memory catalogue. */
    private function store(): CatalogSkills
    {
        return new CatalogSkills($this->db, $this->catalog);
    }

    /** One skill's audit rows, oldest first: [event, name, commit]. */
    private function audits(int $id): array
    {
        $rows = \Pramnos\Auth\AuditLog::query(['event' => 'ai.catalog_skill_*', 'object_type' => 'chat_skill', 'object_id' => (string) $id]);

        return array_reverse(array_map(static fn (array $r): array => [(string) $r['event_type'], $r['metadata']['name'] ?? null, $r['metadata']['commit'] ?? null], $rows));
    }

    /**
     * Read before it is installed: its method, references, evals, licence and commit, and what was
     * left out — a script never among what is taken. Installed, it is off and offered to nobody;
     * turned on, it is offered to everybody who has Ask as `catalog:<name>`, its references read
     * from the database; the audit keeps who installed and who turned on which commit.
     */
    public function testReadInstalledOffThenOnForEverybody(): void
    {
        // Arrange
        $store = $this->store();

        // Act
        $preview = $store->preview('acme/skills/skills/seo-audit');
        $id      = $store->install($this->people['admin'], 'acme/skills/skills/seo-audit@' . MemoryCatalog::A);
        $off     = $store->forReader((int) $this->people['staff']->userid);
        $store->turn($this->people['admin'], (int) $id, true);
        $on      = $store->forReader((int) $this->people['staff']->userid);
        $skill   = $on['catalog:seo-audit'] ?? [];
        $reading = Skills::read($on, 'catalog:seo-audit', 'checklist.md');

        // Assert
        $this->assertSame(['seo-audit', 'MIT', MemoryCatalog::A], [$preview['name'], $preview['licence'], $preview['commit']]);
        $this->assertSame(['checklist.md'], array_keys($preview['references']));
        $this->assertSame(['case.json'], array_keys($preview['evals']));
        $this->assertSame(['scripts/run.sh'], $preview['left_out'], 'a script is never taken');
        $this->assertIsInt($id);
        $this->assertSame([], $off, 'installed off: nobody is offered it');
        $this->assertSame(['catalog:seo-audit'], array_keys($on));
        $this->assertSame(['sql', 'page_speed'], $skill['tools']);
        $this->assertStringContainsString('Audit the pages.', $skill['instructions']);
        $this->assertStringContainsString('acme/skills/skills/seo-audit', $skill['instructions'], 'marked as installed from where');
        $this->assertSame(['checklist.md' => 'The checklist'], $skill['references']);
        $this->assertStringContainsString('Titles under 60', $reading, 'its reference read from the database');
        $this->assertSame([['ai.catalog_skill_installed', 'seo-audit', MemoryCatalog::A], ['ai.catalog_skill_enabled', 'seo-audit', MemoryCatalog::A]], $this->audits((int) $id));
        $this->assertNotContains('scripts/run.sh', array_keys((array) json_decode((string) $this->db->queryBuilder()->table(StoredSkills::TABLE)->where('skill_id', $id)->first()->fields['files'], true)['references']));
    }

    /**
     * Kept to a role, it is offered from that role up; a skill file of the same name wins; a
     * person's "My skills" cannot turn it on or delete it; only somebody who manages skills
     * installs one.
     */
    public function testRoleFilesWinAndWhoMayChangeIt(): void
    {
        // Arrange
        $store = $this->store();
        $id    = (int) $store->install($this->people['admin'], 'acme/skills/skills/seo-audit', '90');
        $store->turn($this->people['admin'], $id, true);

        // Act
        $forStaff = $store->forReader((int) $this->people['staff']->userid);
        $forAdmin = $store->forReader((int) $this->people['admin']->userid);
        $shadowed = $store->forReader((int) $this->people['admin']->userid, ['seo-audit' => ['description' => 'The repository\'s']]);
        $viaMine  = [(new StoredSkills($this->db))->turn($this->people['admin'], $id, false), (new StoredSkills($this->db))->delete($this->people['admin'], $id)];
        $byStaff  = $store->install($this->people['staff'], 'acme/skills/skills/pricing');
        $store->forRole($this->people['admin'], $id, null);
        $opened   = $store->forReader((int) $this->people['staff']->userid);

        // Assert
        $this->assertSame([], $forStaff, 'kept to administrators');
        $this->assertSame(['catalog:seo-audit'], array_keys($forAdmin));
        $this->assertSame([], $shadowed, 'the repository\'s file of that name wins');
        $this->assertSame([false, false], $viaMine, 'not through My skills');
        $this->assertSame('Only an administrator installs a skill for everybody.', $byStaff);
        $this->assertSame(['catalog:seo-audit'], array_keys($opened), 'opened to everybody again');
    }

    /**
     * Never brought up to date by itself: the catalogue's newer commit is said, with what each file
     * changes; taken, it is off again until somebody reads it; up to date is said so.
     */
    public function testANewerVersionIsShownThenTakenOff(): void
    {
        // Arrange
        $store = $this->store();
        $id    = (int) $store->install($this->people['admin'], 'acme/skills/skills/seo-audit@' . MemoryCatalog::A);
        $store->turn($this->people['admin'], $id, true);
        $this->catalog->head = MemoryCatalog::B;

        // Act
        $newer   = $store->upstream($id);
        $taken   = $store->update($this->people['admin'], $id);
        $after   = $store->find($id);
        $current = $store->upstream($id);
        $again   = $store->update($this->people['admin'], $id);

        // Assert
        $this->assertTrue($newer['newer']);
        $this->assertSame(MemoryCatalog::B, $newer['commit']);
        $this->assertSame(['SKILL.md'], array_keys($newer['changes']), 'only what changed');
        $this->assertStringContainsString("-Audit the pages.\n+Audit the pages, and the sitemap.", $newer['changes']['SKILL.md']);
        $this->assertTrue($taken);
        $this->assertSame(['off', MemoryCatalog::B], [$after['status'], $after['commit']], 'off again until read');
        $this->assertStringContainsString('the sitemap', $after['skill']);
        $this->assertFalse($current['newer']);
        $this->assertSame('It is up to date.', $again);
        $this->assertContains(['ai.catalog_skill_updated', 'seo-audit', MemoryCatalog::B], $this->audits($id));
    }

    /**
     * The catalogues searched, one that does not answer said and the others still read; what is
     * refused — a name a skill cannot have, one installed already, a role nobody holds, a source
     * that is none — and a skill deleted, recorded.
     */
    public function testSearchRefusalsAndDeleting(): void
    {
        // Arrange
        $store = $this->store();
        $id    = (int) $store->install($this->people['admin'], 'acme/skills/skills/seo-audit');

        // Act
        $found   = $store->search('seo');
        $refused = [
            $store->install($this->people['admin'], 'acme/skills/skills/pricing', null, 'Bad Name!'),
            $store->install($this->people['admin'], 'acme/skills/skills/seo-audit'),
            $store->install($this->people['admin'], 'acme/skills/skills/pricing', 'no-such-role'),
            $store->install($this->people['admin'], 'not a source'),
        ];
        $deleted = $store->delete($this->people['admin'], $id);
        $gone    = [$store->find($id), $store->delete($this->people['admin'], $id), $store->turn($this->people['admin'], $id, true), $store->upstream($id)];

        // Assert
        $this->assertSame(['acme/skills/skills/seo-audit'], $found['found']);
        $this->assertArrayHasKey('gone/repo', $found['errors'], 'a catalogue that did not answer is said');
        $this->assertSame('"bad name!" is not a name a skill can have; give it another.', $refused[0]);
        $this->assertSame('A skill of that name is installed already.', $refused[1]);
        $this->assertSame('Choose the role it is for.', $refused[2]);
        $this->assertStringStartsWith('A skill source is owner/repo/path', (string) $refused[3]);
        $this->assertTrue($deleted);
        $this->assertSame([null, false, false, 'There is no such skill.'], $gone);
        $this->assertSame(['ai.catalog_skill_deleted', 'seo-audit', MemoryCatalog::A], array_slice($this->audits($id), -1)[0] ?? null, 'recorded last');
    }

    /**
     * `Ai/skillcatalog` as a controller with this store and this person: what the page is given,
     * where each action sends, and what it says.
     */
    private function page(string $who): \Pramnos\Application\Controllers\AiController
    {
        $store = $this->store();
        $user  = $this->people[$who];

        return new class ($store, $user) extends \Pramnos\Application\Controllers\AiController {
            /** @var array<string, mixed> What the view was given */
            public array $given = [];

            public string $shown = '';

            /** @var list<string> */
            public array $said = [];

            /** @var list<string> */
            public array $redirectedTo = [];

            /** Not parent::__construct(): it registers actions against an application this test does not need. */
            public function __construct(private CatalogSkills $store, private object $asker)
            {
            }

            protected function catalogSkills(): CatalogSkills
            {
                return $this->store;
            }

            protected function catalogSkillsAsker(): ?object
            {
                return $this->asker;
            }

            protected function skillTools(): array
            {
                return ['sql' => 'Read the database'];
            }

            public function &getView($name = '', $type = '', $args = array())
            {
                $page = $this;
                $view = new #[\AllowDynamicProperties] class ($page) {
                    public function __construct(private object $page)
                    {
                    }

                    public function __set(string $name, mixed $value): void
                    {
                        $this->page->given[$name] = $value;
                    }

                    public function display(string $template = ''): string
                    {
                        $this->page->shown = $template;

                        return '';
                    }
                };

                return $view;
            }

            protected function addMessage($message)
            {
                $this->said[] = (string) $message;

                return $this;
            }

            protected function addError($error)
            {
                $this->said[] = 'error: ' . $error;

                return $this;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo[] = (string) $url;
            }
        };
    }

    /** A theme's page drawn with what a controller gave it. */
    private function draw(string $theme, array $given): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/ai/skillcatalog.tpl.php';
        $view = new #[\AllowDynamicProperties] class ($given) {
            /** @param array<string, mixed> $properties */
            public function __construct(private array $properties)
            {
            }

            public function __get(string $name): mixed
            {
                return $this->properties[$name] ?? null;
            }

            public function __isset(string $name): bool
            {
                return isset($this->properties[$name]);
            }
        };
        ob_start();
        try {
            (function () use ($path): void {
                include \Pramnos\Tests\Support\ScaffoldView::run($path);
            })->call($view);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The page in each theme: what is installed, off and marked so, a tool Ask does not have said,
     * the repository's files read only; a search, and a skill read before installing, with a
     * script left out and the source pinned to the commit read; an installed one's newer version
     * with what it changes. Somebody who does not manage skills is sent back.
     */
    public function testThePageInEachTheme(): void
    {
        // Arrange — one installed, at A; the catalogue now at B
        $store = $this->store();
        $id    = (int) $store->install($this->people['admin'], 'acme/skills/skills/seo-audit@' . MemoryCatalog::A);
        $this->catalog->head = MemoryCatalog::B;
        [$list, $newer, $staff] = [$this->page('admin'), $this->page('admin'), $this->page('staff')];

        // Act
        $_GET = ['q' => 'pric', 'source' => 'acme/skills/skills/pricing'];
        $list->skillcatalog();
        $_GET = ['skill' => (string) $id];
        $newer->skillcatalog();
        $_GET = [];
        $staff->skillcatalog();
        $pages = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $pages[$theme] = [$this->draw($theme, $list->given), $this->draw($theme, $newer->given)];
        }

        // Assert
        $this->assertSame('skillcatalog', $list->shown);
        $this->assertSame(['acme/skills/skills/pricing'], $list->given['search']['found']);
        $this->assertSame('pricing', $list->given['preview']['name']);
        $this->assertTrue($newer->given['upstream']['newer']);
        $this->assertSame(['error: You do not have access to that screen.'], $staff->said, 'not for staff');
        foreach ($pages as $theme => [$page, $diff]) {
            $this->assertStringContainsString('catalog:seo-audit', $page, $theme);
            $this->assertStringContainsString('Off — not read yet', $page, $theme);
            $this->assertStringContainsString('Ask has no tool called page_speed', $page, $theme . ': a tool Ask does not have is said');
            $this->assertStringContainsString('value="acme/skills/skills/pricing@' . MemoryCatalog::B . '"', $page, $theme . ': pinned to the commit read');
            $this->assertStringContainsString('Install — off until read', $page, $theme);
            $this->assertStringContainsString('Take the newer version', $diff, $theme);
            $this->assertStringContainsString('-Audit the pages.', $diff, $theme);
        }
    }

    /**
     * The actions: install from the preview's pinned source, off; turn on; keep to a role; take a
     * newer version, off again; delete — each saying what it did and going back to the page.
     */
    public function testThePagesActions(): void
    {
        // Arrange
        $page = $this->page('admin');

        // Act
        $_POST = ['source' => 'acme/skills/skills/seo-audit@' . MemoryCatalog::A, 'role' => '', 'as' => ''];
        $page->skillinstall();
        $id    = (int) ($this->store()->all()[0]['id'] ?? 0);
        $_POST = ['skill_id' => (string) $id, 'on' => '1'];
        $page->skillcatalogturn();
        $on    = $this->store()->find($id)['status'];
        $_POST = ['skill_id' => (string) $id, 'role' => '90'];
        $page->skillcatalogrole();
        $role  = $this->store()->find($id)['role'];
        $this->catalog->head = MemoryCatalog::B;
        $_POST = ['skill_id' => (string) $id];
        $page->skillcatalogupdate();
        $after = $this->store()->find($id)['status'];
        $page->skillcatalogdelete();
        $_POST = ['source' => 'not a source'];
        $page->skillinstall();
        $_POST = [];

        // Assert
        $this->assertSame(['on', '90', 'off'], [$on, $role, $after]);
        $this->assertNull($this->store()->find($id), 'deleted');
        $this->assertSame([
            'Installed. It is off until it is read and turned on.', 'Saved.', 'Saved.',
            'Updated. It is off until it is read and turned on again.', 'Deleted.',
        ], array_slice($page->said, 0, 5));
        $this->assertStringStartsWith('error: A skill source is', $page->said[5]);
        $this->assertStringEndsWith('Ai/skillcatalog?skill=' . $id, $page->redirectedTo[0], 'to the one installed');
    }

    /**
     * `skill:list` shows those installed into the database too, with whether they are on — with
     * the skills folder, after its files, and without one, alone.
     */
    public function testSkillListShowsTheDatabasesToo(): void
    {
        // Arrange — one installed and on; a folder with a skill file of its own
        $store = $this->store();
        $store->turn($this->people['admin'], (int) $store->install($this->people['admin'], 'acme/skills/skills/seo-audit@' . MemoryCatalog::A), true);
        $folder = sys_get_temp_dir() . '/pf-skills-' . bin2hex(random_bytes(3));
        mkdir($folder);
        file_put_contents($folder . '/own.md', "---\ndescription: Ours\n---\nDo it.");
        $run = static function (): string {
            $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\SkillList());
            $tester->execute([]);

            return $tester->getDisplay();
        };

        // Act
        $alone = $run();
        Application::getInstance()->applicationInfo['llm']['chat']['skills_path'] = $folder;
        $both  = $run();
        array_map('unlink', glob($folder . '/*') ?: []);
        rmdir($folder);

        // Assert
        $this->assertStringContainsString('catalog:seo-audit  on  acme/skills/skills/seo-audit@aaaaaaa  (database, from the AI settings)', $alone);
        $this->assertStringContainsString('own  own', $both, 'the files first');
        $this->assertStringContainsString('catalog:seo-audit  on', $both);
    }

    /**
     * What fails says why and changes nothing: a catalogue that does not answer, a newer commit
     * with the same files (nothing to take), a role nobody holds, somebody who does not manage
     * skills, a database that does not answer, a preview of no source, nobody asking.
     */
    public function testFailuresSayWhy(): void
    {
        // Arrange
        $store = $this->store();
        $id    = (int) $store->install($this->people['admin'], 'acme/skills/skills/seo-audit@' . MemoryCatalog::A);
        $dead  = new CatalogSkills(new Database(), $this->catalog);

        // Act
        $this->catalog->head = 'cccccccccccccccccccccccccccccccccccccccc';
        $sameFiles = $store->upstream($id);
        $this->catalog->down = true;
        $noAnswer  = [$store->upstream($id), $store->update($this->people['admin'], $id)];
        $this->catalog->down = false;
        $refused   = [$store->forRole($this->people['admin'], $id, 'no-such-role'), $store->update($this->people['staff'], $id),
            $store->forReader(0), $dead->install($this->people['admin'], 'acme/skills/skills/pricing'), $dead->all()];
        $_GET = ['source' => 'not a source'];
        $page = $this->page('admin');
        $page->skillcatalog();
        $_GET = [];
        $nobody = new class extends \Pramnos\Application\Controllers\AiController {
            /** @var list<string> */
            public array $said = [];

            /** No application to register against. */
            public function __construct()
            {
            }

            protected function catalogSkillsAsker(): ?object
            {
                return null;
            }

            protected function addError($error)
            {
                $this->said[] = (string) $error;

                return $this;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
            }
        };
        foreach (['skillcatalog', 'skillinstall', 'skillcatalogupdate', 'skillcatalogdelete'] as $action) {
            $nobody->{$action}();
        }

        // Assert
        $this->assertFalse($sameFiles['newer'], 'a newer commit with the same files is nothing to take');
        $this->assertStringContainsString('404', (string) $noAnswer[0]);
        $this->assertSame($noAnswer[0], $noAnswer[1]);
        $this->assertSame([false, 'There is no such skill.', [], 'The skill could not be installed.', []], $refused);
        $this->assertStringStartsWith('A skill source is', (string) $page->given['error'], 'the preview says why');
        $this->assertSame(array_fill(0, 4, 'You do not have access to that screen.'), $nobody->said);
        $this->assertSame('off', $store->find($id)['status'], 'nothing changed');
    }

    /**
     * Ask's gear leads to the application's skills, after "My skills", for whoever the page gives
     * the address to — whoever manages skills — and to nobody else.
     */
    public function testAsksGearLeadsToThem(): void
    {
        // Arrange
        $urls = ['skills' => '/admin/Ai/skills', 'memories' => '', 'all' => '', 'reviews' => ''];

        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            // Act
            $with    = $this->drawChat($theme, $urls + ['skillcatalog' => '/admin/Ai/skillcatalog']);
            $without = $this->drawChat($theme, $urls + ['skillcatalog' => '']);

            // Assert
            $this->assertMatchesRegularExpression('#My skills</a></li>\s*<li><a href="/admin/Ai/skillcatalog">The application&\#0?39;s skills</a>#', $with, $theme . ': right after My skills');
            $this->assertStringNotContainsString('Ai/skillcatalog', $without, $theme);
        }
        $this->assertSame('Τα skills της εφαρμογής', (require ROOT . '/resources/language/el.php')['The application\'s skills'] ?? null, 'in Greek');
    }

    /** Ask's page in a theme, with these addresses, and nothing else of it. */
    private function drawChat(string $theme, array $urls): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/chat/chat.tpl.php';
        $view = new #[\AllowDynamicProperties] class (['urls' => $urls, 'conversations' => [], 'open' => null]) {
            /** @param array<string, mixed> $properties */
            public function __construct(private array $properties)
            {
            }

            public function __get(string $name): mixed
            {
                return $this->properties[$name] ?? null;
            }

            public function __isset(string $name): bool
            {
                return isset($this->properties[$name]);
            }

            /** insert() and the other helpers: nothing to add. */
            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        };
        ob_start();
        try {
            (function () use ($path): void {
                include \Pramnos\Tests\Support\ScaffoldView::run($path);
            })->call($view);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The diff of two texts keeps what is common and says what went and what came; two very long
     * ones are shown whole; the tools a skill names that Ask does not have are said.
     */
    public function testDiffAndUnknownTools(): void
    {
        // Act
        $diff = CatalogSkills::diff("a\nb\nc", "a\nB\nc\nd");
        $long = CatalogSkills::diff(str_repeat("x\n", CatalogSkills::DIFF_LINES + 1), 'y');

        // Assert
        $this->assertSame(" a\n-b\n+B\n c\n+d", $diff);
        $this->assertStringEndsWith("\n+y", $long);
        $this->assertSame(['page_speed'], CatalogSkills::unknownTools(['sql', 'page_speed'], ['sql' => []]));
    }
}

/** A catalogue answering from memory: acme/skills, with seo-audit and pricing, at commit A or — once `head` moves — B. */
final class MemoryCatalog extends SkillCatalog
{
    public const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** The commit HEAD names. */
    public string $head = self::A;

    /** Whether GitHub answers at all. */
    public bool $down = false;

    /** The commit a ref names; a catalogue that is not acme/skills, or GitHub down, does not answer. */
    public function commit(string $owner, string $repo, string $ref = 'HEAD'): string
    {
        if ($this->down || $owner . '/' . $repo !== 'acme/skills') {
            throw new \RuntimeException('GitHub answered 404 for ' . $owner . '/' . $repo . '.');
        }

        return $ref === 'HEAD' ? $this->head : $ref;
    }

    /** MIT. */
    public function licence(string $owner, string $repo): string
    {
        return 'MIT';
    }

    /** @return list<string> The files at a commit */
    public function tree(string $owner, string $repo, string $commit): array
    {
        return array_keys($this->files($commit));
    }

    /** One file's text at a commit. */
    protected function raw(string $owner, string $repo, string $commit, string $file): string
    {
        return $this->files($commit)[$file];
    }

    /** @return array<string, string> The repository at a commit */
    private function files(string $commit): array
    {
        // B changes the method; any other commit has A's files.
        $method = $commit === self::B ? 'Audit the pages, and the sitemap.' : 'Audit the pages.';

        return [
            'skills/seo-audit/SKILL.md'                => "---\nname: seo-audit\ndescription: An SEO audit\ntools: [sql, page_speed]\n---\n# SEO\n" . $method . "\nThen report.",
            'skills/seo-audit/references/checklist.md' => "# The checklist\nTitles under 60 characters.",
            'skills/seo-audit/evals/case.json'         => '{"q": "audit"}',
            'skills/seo-audit/scripts/run.sh'          => 'rm -rf /',
            'skills/pricing/SKILL.md'                  => "---\nname: pricing\ndescription: Pricing\n---\nPrice it.",
        ];
    }
}
