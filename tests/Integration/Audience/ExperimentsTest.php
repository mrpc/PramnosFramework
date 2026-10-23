<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Experiments as Screen;
use Pramnos\Audience\Experiments;
use Pramnos\Audience\Explained;
use Pramnos\Audience\Filter;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\Rules\Engine;
use Pramnos\Audience\Rules\Rules;
use Pramnos\Audience\Segments;

/**
 * A/B tests on MySQL, prefixed MySQL and PostgreSQL: a variant for good per person or visitor, by
 * share; a visitor's carried over to the account; results with how sure they are and how many more
 * are needed; a winner for everybody; a filter condition and a rule's split on a test; the screen.
 */
#[CoversClass(Experiments::class)]
#[CoversClass(Screen::class)]
#[CoversClass(Filter::class)]
#[CoversClass(Explained::class)]
#[CoversClass(PersonEvents::class)]
class ExperimentsTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }
    use AdminScreen;

    /** Clear the request, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $this->clearRequest();
        $this->probeTearDown();
    }

    /** A running 50/50 test with a goal of paying within a week. */
    private function test(?int $segment = null): int
    {
        $id = Experiments::create('annual-first', 'Annual first', [['key' => 'A', 'share' => 50, 'label' => 'As today'], ['key' => 'B', 'share' => 50, 'label' => 'Annual first']],
            'payment.made', 7, $segment, 2);
        Experiments::start($id, 2);

        return $id;
    }

    /**
     * A test is checked when made — a name for the code, two variants or more with their own keys
     * and shares adding to 100, a goal over a day or more — and cannot change once started.
     */
    #[DataProvider('databases')]
    public function testATestIsCheckedAndFixedOnceStarted(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $two = [['key' => 'A', 'share' => 50], ['key' => 'B', 'share' => 50]];
        $refusals = [];

        // Act
        foreach ([['Bad Slug', 'x', $two, 7], ['ok', ' ', $two, 7], ['ok', 'x', [['key' => 'A', 'share' => 100]], 7],
            ['ok', 'x', [['key' => 'A', 'share' => 50], ['key' => 'A', 'share' => 50]], 7], ['ok', 'x', [['key' => 'A', 'share' => 40], ['key' => 'B', 'share' => 40]], 7],
            ['ok', 'x', $two, 0]] as [$slug, $name, $variants, $days]) {
            try {
                Experiments::create($slug, $name, $variants, null, $days);
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        $id = Experiments::create('ok', 'Draft', $two);
        Experiments::update($id, ['name' => 'Renamed', 'variants' => [['key' => 'A', 'share' => 30], ['key' => 'B', 'share' => 70]]]);
        Experiments::start($id);
        try {
            Experiments::update($id, ['name' => 'Again']);
        } catch (\InvalidArgumentException $e) {
            $refusals[] = $e->getMessage();
        }

        // Assert
        $this->assertSame([
            'A test is named for the code in lower-case letters, digits, . _ -.',
            'A test needs a name.',
            'A test has two variants or more, their shares adding up to 100.',
            'Each variant has its own key and a share of at least 1.',
            'A test has two variants or more, their shares adding up to 100.',
            'The goal is counted over a day or more.',
            'A test that has started cannot be changed: its numbers would mean nothing.',
        ], $refusals);
        $test = Experiments::get($id);
        $this->assertSame(['Renamed', 'running', [30, 70]], [$test['name'], $test['status'], array_column($test['variants'], 'share')]);
        $this->assertSame('A', $test['variants'][0]['label'], 'no label is its key');
        $this->assertCount(1, Experiments::all());
    }

    /**
     * A variant is given by share and kept: the same person asked again gets the same; a draft
     * gives none; a stopped test gives none, a decided one its winner to everybody.
     */
    #[DataProvider('databases')]
    public function testAVariantIsGivenByShareAndKept(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $draft = Experiments::create('draft', 'Draft', [['key' => 'A', 'share' => 50], ['key' => 'B', 'share' => 50]]);
        $id    = $this->test();

        // Act
        $counts = ['A' => 0, 'B' => 0];
        for ($i = 0; $i < 200; $i++) {
            $counts[Experiments::variant('annual-first', null, sprintf('%032x', $i))]++;
        }
        $first  = Experiments::variant('annual-first', $this->ids['anna']);
        $again  = Experiments::variant('annual-first', $this->ids['anna']);
        $none   = [Experiments::variant('draft', 1), Experiments::variant('nothing', 1)];
        Experiments::stop($id);
        $stopped = Experiments::variant('annual-first', $this->ids['bob']);
        Experiments::decide($id, 'B');
        $decided = Experiments::variant('annual-first', $this->ids['bob']);

        // Assert
        $this->assertGreaterThan(70, $counts['A']);
        $this->assertGreaterThan(70, $counts['B']);
        $this->assertSame($first, $again);
        $this->assertSame([null, null], $none);
        $this->assertNull($stopped);
        $this->assertSame('B', $decided);
        $this->assertSame('draft', Experiments::get($draft)['status']);
        $this->expectExceptionMessage('The test has no variant Z.');
        Experiments::decide($id, 'Z');
    }

    /**
     * A test on a segment gives people outside it, and visitors, no variant; a visitor is known by a
     * cookie set once; signing in carries what the visitor was shown over to the account — unless the
     * account had its own — once a session.
     */
    #[DataProvider('databases')]
    public function testSegmentsVisitorsAndCarryingOver(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($greek);
        $limited = Experiments::create('greek-only', 'Greek only', [['key' => 'A', 'share' => 50], ['key' => 'B', 'share' => 50]], segment: $greek);
        Experiments::start($limited);
        $open = $this->test();

        // Act
        $outside = Experiments::variant('greek-only', $this->ids['bob']);
        $inside  = Experiments::variant('greek-only', $this->ids['anna']);
        $visitorView = Experiments::variant('annual-first');
        $cookie  = $_COOKIE[Experiments::COOKIE];
        $same    = Experiments::visitor();
        $limitedForVisitor = Experiments::variant('greek-only');
        Experiments::variant('annual-first', $this->ids['cara']);
        $held    = Experiments::variant('annual-first', $this->ids['cara']);
        $this->signIn(1, $this->ids['bob']);
        $asBob   = Experiments::variant('annual-first');
        $moved   = Experiments::adopt($cookie, $this->ids['cara']);

        // Assert
        $this->assertNull($outside);
        $this->assertContains($inside, ['A', 'B']);
        $this->assertSame($cookie, $same);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $cookie);
        $this->assertNull($limitedForVisitor);
        $this->assertSame($visitorView, $asBob, 'bob signed in and kept what he saw as a visitor');
        $this->assertSame(0, $moved, 'nothing left under the visitor');
        $this->assertSame($held, Experiments::variant('annual-first', $this->ids['cara']));
        $this->assertSame(0, Experiments::adopt('', 1));
        $this->assertNotNull($open);
    }

    /**
     * Results: per variant the people, how many reached the goal within its days of being given it,
     * the rate, how sure the difference from the first is, and how many more each needs; visitors
     * apart.
     */
    #[DataProvider('databases')]
    public function testResultsSayHowSureAndHowManyMore(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = $this->test();
        $given = [];
        foreach (['anna', 'bob', 'cara'] as $name) {
            $given[$name] = Experiments::variant('annual-first', $this->ids[$name]);
        }
        Experiments::variant('annual-first', null, str_repeat('a', 32));
        PersonEvents::record($this->ids['anna'], 'payment.made', [], time() + 3600);
        PersonEvents::record($this->ids['bob'], 'payment.made', [], time() + 30 * 86400);

        // Act
        $results = Experiments::results($id);
        $math    = Experiments::compare(['people' => 1000, 'converted' => 100, 'rate' => 0.1], ['people' => 1000, 'converted' => 130, 'rate' => 0.13]);
        $even    = Experiments::compare(['people' => 10, 'converted' => 1, 'rate' => 0.1], ['people' => 10, 'converted' => 1, 'rate' => 0.1]);
        $empty   = Experiments::compare(['people' => 0, 'converted' => 0, 'rate' => null], ['people' => 3, 'converted' => 1, 'rate' => 0.3]);

        // Assert
        $byKey = array_column($results['variants'], null, 'key');
        $this->assertSame(3, $byKey['A']['people'] + $byKey['B']['people']);
        $this->assertSame(1, $byKey[$given['anna']]['converted'] + ($given['anna'] === $given['bob'] ? 0 : 0), 'anna paid within the week; bob too late');
        $this->assertSame(1, $results['visitors']);
        $this->assertNull($results['variants'][0]['confidence'], 'the first variant is the baseline');
        $this->assertEqualsWithDelta(0.964, $math['confidence'], 0.005);
        $this->assertEqualsWithDelta(772, $math['needed'], 3);
        $this->assertSame(0.0, round((float) $even['confidence'], 6));
        $this->assertNull($even['needed']);
        $this->assertSame(['confidence' => null, 'needed' => null], $empty);
    }

    /**
     * A filter can ask who was given a variant, and a rule's split can follow the test, so the
     * rule's numbers and the test's are the same people.
     */
    #[DataProvider('databases')]
    public function testAFilterAndARuleFollowTheTest(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->test();
        $given = [];
        foreach (['anna', 'bob', 'cara'] as $name) {
            $given[$name] = Experiments::variant('annual-first', $this->ids[$name]);
        }
        $bees  = array_keys(array_filter($given, static fn ($v): bool => $v === 'B'));
        $queue = $this->fakeQueue();
        $rule  = Rules::create('Split', ['trigger' => ['type' => 'event', 'event' => 'x'], 'steps' => [
            's' => ['type' => 'split', 'experiment' => 'annual-first', 'variants' => [['key' => 'A', 'next' => null], ['key' => 'B', 'next' => 'b']]],
            'b' => ['type' => 'action', 'action' => 'group_add', 'params' => ['group' => $this->groups['Press']], 'next' => null],
        ]], 2);
        Rules::setStatus($rule, 'active', 2);

        // Act
        $in      = $this->names(Filter::fromArray(['conditions' => [['experiment' => 'annual-first', 'variant' => 'B']]])->ids());
        $out     = $this->names(Filter::fromArray(['conditions' => [['experiment' => 'annual-first', 'variant' => 'B', 'op' => 'not_in']]])->ids());
        $unknown = Filter::fromArray(['conditions' => [['experiment' => 'nothing', 'variant' => 'B']]])->count();
        $why     = Explained::condition(Filter::fromArray(['conditions' => [['experiment' => 'annual-first', 'variant' => 'B']]])->explain($this->ids['anna'])['conditions'][0]);
        foreach (['anna', 'bob', 'cara'] as $name) {
            Engine::onEvent('x', $this->ids[$name]);
        }
        $this->work($queue);
        $variants = array_column($this->db->queryBuilder()->table('pramnos.rule_runs')->where('ruleid', $rule)->getAll(), 'variant', 'personid');

        // Assert
        sort($bees);
        $this->assertSame($bees, $in);
        $this->assertSame(array_values(array_diff(['anna', 'bob', 'cara'], $bees)), $out);
        $this->assertSame(0, $unknown);
        $this->assertSame(['Annual first', 'was given', 'B', 'variant ' . $given['anna']], array_values($why));
        foreach (['anna', 'bob', 'cara'] as $name) {
            $this->assertSame($given[$name], $variants[$this->ids[$name]] ?? null, $name);
        }
    }

    /**
     * The screen: the list with the variant ahead, a test's results, the form, and start, stop and
     * make-a-winner — each in the flash — in every theme; below the floor nothing.
     */
    #[DataProvider('databases')]
    public function testTheScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $variants = [['key' => 'A', 'label' => 'Today', 'share' => '50'], ['key' => 'B', 'label' => 'New', 'share' => '50'], ['key' => '', 'share' => '']];

        // Act
        $this->request('POST', null, [], ['slug' => 'hero', 'name' => 'Hero', 'variants' => $variants, 'goalevent' => 'payment.made', 'goaldays' => '7']);
        $screen->save();
        $id = Experiments::all()[0]['id'];
        $this->request('POST', null, [], ['slug' => 'hero', 'name' => 'Again', 'variants' => $variants]);
        $screen->save();
        $this->request('POST', null, [], ['experimentid' => (string) $id, 'name' => 'Hero', 'variants' => [['key' => 'A', 'share' => '100']]]);
        $screen->save();
        $this->request('GET', $id);
        $screen->edit();
        $forms = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $forms[$theme] = $this->render($theme, 'experiments/edit.html.php', (array) $screen->view);
        }
        $this->request('POST', $id);
        $screen->start();
        Experiments::variant('hero', $this->ids['anna']);
        $this->request('GET');
        $screen->display();
        $list = (array) $screen->view;
        $this->request('GET', $id);
        $screen->view();
        $page = (array) $screen->view;
        $pages = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $pages[$theme] = $this->render($theme, 'experiments/view.html.php', $page) . $this->render($theme, 'experiments/experiments.html.php', $list);
        }
        $this->request('POST', $id, [], ['variant' => 'B']);
        $screen->decide();
        $this->request('POST', $id, [], ['variant' => 'Q']);
        $screen->decide();
        $this->request('GET', 424242);
        $gone = $screen->view();
        $this->signIn(1);
        $below = $this->screen(Screen::class);
        $floor = [$below->display(), $below->view(), $below->edit()];
        $below->save();
        $below->start();

        // Assert
        $this->assertSame([
            ['message', 'Saved.'],
            ['error', 'Another test already has that name for the code.'],
            ['error', 'A test has two variants or more, their shares adding up to 100.'],
            ['message', 'The test has started.'],
            ['message', 'Everybody gets B from now on.'],
            ['error', 'The test has no variant Q.'],
            ['error', 'That test no longer exists.'],
        ], $screen->flash);
        $this->assertSame('B', Experiments::get($id)['winner']);
        $this->assertSame(1, $list['tests'][0]['people']);
        $this->assertNull($gone);
        $this->assertSame([null, null, null], $floor);
        foreach ($forms as $theme => $form) {
            $this->assertStringContainsString('name="variants[0][key]"', $form, $theme);
            $this->assertStringContainsString('readonly', $form, $theme . ': the slug of a test is fixed');
        }
        foreach ($pages as $theme => $html) {
            $this->assertStringContainsString("variant('hero')", $html, $theme);
            $this->assertStringContainsString('Make it the winner', $html, $theme);
            $this->assertStringContainsString('the baseline', $html, $theme);
            $this->assertStringContainsString('Hero', $html, $theme);
        }
    }

    /**
     * The screen's other ways out: a form for a test that is gone goes back to the list, a running
     * test stops, and before the tables exist every action says to run migrate.
     */
    #[DataProvider('databases')]
    public function testTheScreenStopsAndRefuses(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn();
        $id     = $this->test();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET', 424242);
        $gone = $screen->edit();
        $this->request('POST', $id);
        $screen->stop();
        $status = Experiments::get($id)['status'];
        $this->db->schema()->dropTableIfExists('pramnos.experiment_assignments');
        $this->probeChanged($this->db->type);
        \Pramnos\Audience\Installed::reset();
        $this->request('GET');
        $refused = $screen->display();

        // Assert
        $this->assertNull($gone);
        $this->assertSame('stopped', $status);
        $this->assertNull($refused);
        $this->assertSame([
            ['error', 'That test no longer exists.'],
            ['message', 'The test has stopped.'],
            ['error', 'A/B tests are not installed here yet: run migrate.'],
        ], $screen->flash);
    }
}
