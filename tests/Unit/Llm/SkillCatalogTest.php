<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\SkillAdd;
use Pramnos\Console\Commands\SkillCommand;
use Pramnos\Console\Commands\SkillEnable;
use Pramnos\Console\Commands\SkillList;
use Pramnos\Console\Commands\SkillOutdated;
use Pramnos\Console\Commands\SkillSearch;
use Pramnos\Console\Commands\SkillUpdate;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use Pramnos\Llm\Chat\SkillCatalog;
use Pramnos\Llm\Chat\SkillLock;
use Pramnos\Llm\Chat\Skills;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Skills taken from catalogues — GitHub repositories of skill folders — against a GitHub that
 * answers from fakes.
 *
 * What has to hold: only the text a skill is made of is taken, a script never; a skill taken is
 * locked at its commit with its hash and licence and is off until somebody turns it on; upstream
 * changes are said, never taken by themselves, and taking them turns the skill off again; a skill
 * changed here is not overwritten — upstream's is put beside it and each file said as changed here,
 * upstream or both — unless forced; a bad source is refused.
 */
#[CoversClass(SkillCatalog::class)]
#[CoversClass(SkillLock::class)]
#[CoversClass(SkillCommand::class)]
#[CoversClass(SkillAdd::class)]
#[CoversClass(SkillEnable::class)]
#[CoversClass(SkillOutdated::class)]
#[CoversClass(SkillUpdate::class)]
#[CoversClass(SkillSearch::class)]
#[CoversClass(SkillList::class)]
#[CoversClass(Skills::class)]
final class SkillCatalogTest extends LlmTestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pf-catalog-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->configure(['chat' => ['skills_path' => $this->dir, 'skill_catalogues' => ['o/r']]]);
        SkillCommand::$catalog = null;
    }

    protected function tearDown(): void
    {
        Client::resetFakes();
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * GitHub as the fakes answer it: the default branch at `$commit`, a tree of one skill folder with
     * a script and an image beside its text, and the skill's text as `$version` says.
     */
    private static function github(string $commit, string $version = 'v1'): void
    {
        $raw = 'https://raw.githubusercontent.com/o/r/' . $commit . '/skills/crm/';
        Client::fake([
            'https://api.github.com/repos/o/r/commits/HEAD'    => ClientResponse::make(['sha' => $commit]),
            'https://api.github.com/repos/o/r/commits/' . self::A => ClientResponse::make(['sha' => self::A]),
            'https://api.github.com/repos/o/r/commits?*'       => ClientResponse::make([['sha' => $commit]]),
            'https://api.github.com/repos/o/r/license'         => ClientResponse::make(['license' => ['spdx_id' => 'MIT']]),
            'https://api.github.com/repos/o/r/git/trees/*'     => ClientResponse::make(['tree' => [
                ['path' => 'skills/crm/SKILL.md', 'type' => 'blob'],
                ['path' => 'skills/crm/references/emails.md', 'type' => 'blob'],
                ['path' => 'skills/crm/evals/evals.json', 'type' => 'blob'],
                ['path' => 'skills/crm/scripts/run.py', 'type' => 'blob'],
                ['path' => 'skills/crm/logo.png', 'type' => 'blob'],
                ['path' => 'skills/seo/SKILL.md', 'type' => 'blob'],
                ['path' => 'skills/crm/references', 'type' => 'tree'],
            ]]),
            $raw . 'SKILL.md'             => ClientResponse::make("---\ndescription: Loyalty " . $version . "\ntools: [audience_why, nope]\n---\nStart from the cohort."),
            $raw . 'references/emails.md' => ClientResponse::make('# Emails ' . $version),
            $raw . 'evals/evals.json'     => ClientResponse::make('{"evals": []}'),
            $raw . '*'                    => static fn (): ClientResponse => throw new \LogicException('a file that is not taken was fetched'),
        ]);
    }

    /** A command, run. @return array{0: int, 1: string} */
    private static function runCommand(object $command, array $input = []): array
    {
        $tester = new CommandTester($command);
        $code   = $tester->execute($input);

        return [$code, $tester->getDisplay()];
    }

    /**
     * Adding: the skill's text is taken, its script and image are listed as left out and never
     * fetched; it is locked at its commit, hash and licence, off; it is not offered until enabled.
     */
    public function testAddedSkillIsTakenLockedAndOffUntilEnabled(): void
    {
        // Arrange
        self::github(self::A);

        // Act
        [$code, $said] = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/crm']);
        $entry   = (new SkillLock($this->dir))->get('crm');
        $offered = array_keys(Skills::load($this->dir));
        [, $listed] = self::runCommand(new SkillList());
        [$on]    = self::runCommand(new SkillEnable(), ['skill' => 'crm']);
        $after   = array_keys(Skills::load($this->dir));
        [$again, $twice] = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/crm']);

        // Assert
        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir . '/crm/SKILL.md');
        $this->assertFileExists($this->dir . '/crm/references/emails.md');
        $this->assertFileDoesNotExist($this->dir . '/crm/scripts/run.py', 'a script is never written');
        $this->assertStringContainsString('left out: scripts/run.py', $said);
        $this->assertStringContainsString('left out: logo.png', $said);
        $this->assertStringContainsString('skill:enable crm', $said);
        $this->assertSame(['source' => 'o/r/skills/crm', 'commit' => self::A, 'licence' => 'MIT', 'enabled' => false],
            array_intersect_key($entry, ['source' => 1, 'commit' => 1, 'licence' => 1, 'enabled' => 1]));
        $this->assertSame(SkillLock::hash($this->dir . '/crm'), $entry['hash']);
        $this->assertSame([], $offered, 'off: not offered');
        $this->assertStringContainsString('crm  off (not read yet)  o/r/skills/crm@aaaaaaa', $listed);
        $this->assertSame(0, $on);
        $this->assertSame(['crm'], $after);
        $this->assertSame($entry['hash'], (new SkillLock($this->dir))->get('crm')['approved_hash'], 'what was read');
        $this->assertSame(1, $again, 'taken once');
        $this->assertStringContainsString('already a skill called crm', $twice);
    }

    /**
     * Upstream moving on is said; taking it replaces the files and turns the skill off again; a skill
     * changed here is said, not overwritten — upstream's beside it, each file named — unless forced.
     */
    public function testUpstreamChangesAreSaidAndTakenOnlyWhenAsked(): void
    {
        // Arrange — taken and on at A; upstream moves to B
        self::github(self::A);
        self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/crm']);
        self::runCommand(new SkillEnable(), ['skill' => 'crm']);
        self::github(self::B, 'v2');

        // Act — said, then taken while unchanged here
        [, $outdated] = self::runCommand(new SkillOutdated());
        [$code, $updated] = self::runCommand(new SkillUpdate(), ['skill' => 'crm']);
        $entry = (new SkillLock($this->dir))->get('crm');
        $text  = (string) file_get_contents($this->dir . '/crm/references/emails.md');
        // …changed here, then upstream moves again
        file_put_contents($this->dir . '/crm/SKILL.md', "---\ndescription: Ours\n---\nOur method.");
        [, $changed] = self::runCommand(new SkillOutdated());
        Client::fake([]);
        self::github(self::A, 'v3');
        [$refused, $merge] = self::runCommand(new SkillUpdate(), ['skill' => 'crm', '--ref' => self::A]);
        $kept = (string) file_get_contents($this->dir . '/crm/SKILL.md');
        [$forced] = self::runCommand(new SkillUpdate(), ['skill' => 'crm', '--ref' => self::A, '--force' => true]);

        // Assert
        $this->assertStringContainsString('upstream has bbbbbbb', $outdated);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('off again until it is read', $updated);
        $this->assertSame([self::B, false], [$entry['commit'], $entry['enabled']], 'off again');
        $this->assertSame('# Emails v2', $text);
        $this->assertStringContainsString('changed here', $changed);
        $this->assertSame(1, $refused);
        $this->assertSame("---\ndescription: Ours\n---\nOur method.", $kept, 'not overwritten');
        $this->assertFileExists($this->dir . '/crm/.pf-upstream/SKILL.md');
        $this->assertStringContainsString('SKILL.md: changed here and upstream', $merge);
        $this->assertStringContainsString('references/emails.md: changed upstream', $merge);
        $this->assertSame(0, $forced);
        $this->assertStringContainsString('Loyalty v3', (string) file_get_contents($this->dir . '/crm/SKILL.md'));
    }

    /**
     * Searching names the catalogues' skills by a word in their folder; a bad source, a name a skill
     * cannot have, an unknown skill to enable or update, no lock, and no folder are refused or said.
     */
    public function testSearchAndWhatIsRefused(): void
    {
        // Arrange
        self::github(self::A);
        file_put_contents($this->dir . '/own.md', 'Our own.');

        // Act
        [, $found] = self::runCommand(new SkillSearch(), ['word' => 'cr']);
        [, $all]   = self::runCommand(new SkillSearch());
        [$bad]     = self::runCommand(new SkillAdd(), ['source' => 'o/r/../etc']);
        [$badAs]   = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/crm', '--as' => 'no spaces!']);
        [$own, $ownSaid] = self::runCommand(new SkillEnable(), ['skill' => 'own']);
        [$up]      = self::runCommand(new SkillUpdate(), ['skill' => 'own']);
        [, $none]  = self::runCommand(new SkillOutdated());
        $this->configure(['chat' => []]);
        [$noFolder, $noFolderSaid] = self::runCommand(new SkillList());

        // Assert
        $this->assertSame("o/r/skills/crm\n", $found);
        $this->assertStringContainsString('o/r/skills/seo', $all);
        $this->assertSame(1, $bad);
        $this->assertSame(1, $badAs);
        $this->assertSame(1, $own);
        $this->assertStringContainsString('of the application\'s own is on as it is', $ownSaid);
        $this->assertSame(1, $up);
        $this->assertStringContainsString('No skills taken from a catalogue.', $none);
        $this->assertSame(1, $noFolder);
        $this->assertStringContainsString('No skills folder', $noFolderSaid);
    }

    /**
     * The list says the tools a skill names that the application's tools do not have; the loader
     * logs them too, once, when a conversation declares the skills.
     */
    public function testUnknownToolsAreSaid(): void
    {
        // Arrange
        self::github(self::A);
        self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/crm']);
        file_put_contents($this->dir . '/setup.php', '<?php return ["tools" => ["audience_why" => ["description" => "x"]]];');

        // Act
        [, $listed] = self::runCommand(new SkillList(), ['--setup' => $this->dir . '/setup.php']);
        unlink($this->dir . '/setup.php');

        // Assert
        $this->assertStringContainsString('names tools nobody declared: nope', $listed);
        $this->assertStringNotContainsString('audience_why', explode('nobody declared:', $listed)[1] ?? '');
    }

    /**
     * What GitHub can answer wrong, and what is said: no commit, a folder with no SKILL.md, a file
     * that is not there, one over the size taken (left out, not written), a licence it will not
     * name, a catalogue it will not list, an update already at its commit; a token, when there is
     * one, is sent.
     */
    public function testWhatGithubAnswersWrongIsSaid(): void
    {
        // Arrange — no commit named
        Client::fake(['https://api.github.com/*' => ClientResponse::make(['message' => 'Not Found'], 404)]);
        [$noCommit, $noCommitSaid] = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/crm']);
        [, $searchSaid] = self::runCommand(new SkillSearch(), ['word' => 'x']);
        Client::fake(['https://api.github.com/repos/o/r/commits/HEAD' => ClientResponse::make(['sha' => 'nope'])]);
        [$badSha] = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/crm']);
        // A folder without SKILL.md, one file missing upstream, one too big
        $raw  = 'https://raw.githubusercontent.com/o/r/' . self::A . '/skills/';
        $sent = [];
        Client::fake([
            'https://api.github.com/repos/o/r/commits/HEAD' => static function (Client $request) use (&$sent): ClientResponse {
                $sent[] = $request;

                return ClientResponse::make(['sha' => self::A]);
            },
            'https://api.github.com/repos/o/r/license'      => ClientResponse::make([], 500),
            'https://api.github.com/repos/o/r/git/trees/*'  => ClientResponse::make(['tree' => [
                ['path' => 'skills/empty/references/a.md', 'type' => 'blob'],
                ['path' => 'skills/big/SKILL.md', 'type' => 'blob'],
                ['path' => 'skills/big/references/huge.md', 'type' => 'blob'],
                ['path' => 'skills/gone/SKILL.md', 'type' => 'blob'],
            ]]),
            $raw . 'empty/references/a.md' => ClientResponse::make('# A'),
            $raw . 'big/SKILL.md'          => ClientResponse::make('Big.'),
            $raw . 'big/references/huge.md' => ClientResponse::make(str_repeat('x', SkillCatalog::FILE_MAX + 1)),
            $raw . 'gone/SKILL.md'         => ClientResponse::make('Not Found', 404),
        ]);
        putenv('GITHUB_TOKEN=t0k');
        [$empty, $emptySaid] = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/empty']);
        putenv('GITHUB_TOKEN');
        [$gone, $goneSaid] = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/gone']);
        [$big, $bigSaid] = self::runCommand(new SkillAdd(), ['source' => 'o/r/skills/big']);
        // Already at its commit
        [$same, $sameSaid] = self::runCommand(new SkillUpdate(), ['skill' => 'big']);
        // Upstream that will not answer, for outdated and update
        Client::fake(['https://api.github.com/*' => ClientResponse::make([], 500)]);
        [, $outdatedSaid] = self::runCommand(new SkillOutdated());
        [$cannot] = self::runCommand(new SkillUpdate(), ['skill' => 'big']);

        // Assert
        $this->assertSame(1, $noCommit);
        $this->assertStringContainsString('GitHub answered 404', $noCommitSaid);
        $this->assertStringContainsString('o/r could not be read', $searchSaid);
        $this->assertSame(1, $badSha);
        $this->assertSame(1, $empty);
        $this->assertStringContainsString('There is no SKILL.md', $emptySaid);
        $this->assertSame('Bearer t0k', $sent[0]->requestHeaders()['Authorization'] ?? null, 'a token, when there is one');
        $this->assertSame(1, $gone);
        $this->assertStringContainsString('GitHub answered 404 for skills/gone/SKILL.md', $goneSaid);
        $this->assertSame(0, $big);
        $this->assertStringContainsString('left out: references/huge.md (over', $bigSaid);
        $this->assertStringContainsString('no licence named', $bigSaid);
        $this->assertFileDoesNotExist($this->dir . '/big/references/huge.md');
        $this->assertSame([0, true], [$same, str_contains($sameSaid, 'big is up to date.')]);
        $this->assertStringContainsString('upstream not read', $outdatedSaid);
        $this->assertSame(1, $cannot);
        $this->assertInstanceOf(SkillLock::class, SkillLock::forApplication());
    }
}
