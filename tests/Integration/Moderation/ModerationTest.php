<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Moderation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Commands;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\ModeratorCommands;
use Pramnos\Moderation\Gate;
use Pramnos\Moderation\Moderation;
use Pramnos\Moderation\Reports;
use Pramnos\Moderation\Subject;
use Pramnos\Tests\Integration\Conversations\ConversationsProbe;

/**
 * Moderation on each backend: restrictions — a ban or a mute, in a scope or everywhere, for a while
 * or for good — and lifting them; kicks told to whoever listens; reports, once per reporter; what a
 * message meets on its way (Gate); and the conversations with the `moderation` feature: refusals,
 * the filter, and the moderators' commands.
 */
#[CoversClass(Moderation::class)]
#[CoversClass(Gate::class)]
#[CoversClass(Reports::class)]
#[CoversClass(ModeratorCommands::class)]
#[CoversClass(Messages::class)]
#[CoversClass(\Pramnos\Conversations\Access::class)]
#[CoversClass(\Pramnos\Moderation\Events\Kicked::class)]
final class ModerationTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The rules every test starts from: generous, so only what a test tightens refuses. */
    private function rules(array $rules = [], bool $feature = false): void
    {
        $app = Application::getInstance();
        $app->applicationInfo['moderation'] = $rules + ['rate' => ['messages' => 1000, 'per' => 60]];
        $app->applicationInfo['conversations'] = ['usertype' => 30];
        $app->applicationInfo['features'] = $feature ? ['conversations', 'moderation'] : ['conversations'];
    }

    /** Leave the application as it was. */
    protected function tearDown(): void
    {
        $app = Application::getInstance();
        unset($app->applicationInfo['moderation'], $app->applicationInfo['features']);
        Commands::reset();
        $this->probeTearDown();
    }

    /** A visitor nobody else is: the cache's counters are this test's alone. */
    private function stranger(): Subject
    {
        return Subject::visitor('v-' . bin2hex(random_bytes(6)));
    }

    /**
     * A ban holds over a mute and the longer over the shorter; one everywhere holds in every scope,
     * one in a scope only there; an expired one holds nowhere; lifting ends what is in force, and
     * every step is in the log.
     */
    #[DataProvider('databases')]
    public function testRestrictionsBansMutesAndLifting(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->rules();
        $maria = Subject::account($this->ids['maria']);
        $ip    = Subject::ip('203.0.113.4');

        // Act
        $none = Moderation::restriction([$maria], 'conversation:1');
        Moderation::mute($maria, 'conversation:1', 600, 'Ύβρεις', $this->ids['eleni']);
        $muted = Moderation::restriction([$maria, $ip], 'conversation:1');
        Moderation::ban($ip, '', null, 'Spam');
        $banned   = Moderation::restriction([$maria, $ip], 'conversation:1');
        $elsewhere = Moderation::restriction([$maria], 'conversation:2');
        $expired  = Moderation::restriction([$maria], 'conversation:1', time() + 601);
        $list     = Moderation::restrictions(null, $maria);
        $lifted   = [Moderation::lift($maria, 'conversation:1', 'mute', $this->ids['eleni']), Moderation::lift($maria, 'conversation:1')];
        $after    = Moderation::restriction([$maria], 'conversation:1');
        $log      = array_column(Moderation::history(null, $maria), 'action');

        // Assert
        $this->assertNull($none);
        $this->assertSame(['mute', 'conversation:1', 'Ύβρεις'], [$muted['effect'], $muted['scope'], $muted['reason']]);
        $this->assertSame(['ban', '', null], [$banned['effect'], $banned['scope'], $banned['until']], 'the address\'s ban everywhere, over her mute');
        $this->assertNull($elsewhere, 'her mute is only where it was put');
        $this->assertNull($expired);
        $this->assertSame([['mute', 'conversation:1', $this->ids['eleni']]], array_map(static fn (array $r): array => [$r['effect'], $r['scope'], $r['by']], $list));
        $this->assertSame([1, 0], $lifted, 'nothing left to lift the second time');
        $this->assertNull($after);
        $this->assertSame(['lift', 'mute'], $log, 'newest first');
        $this->assertCount(1, Moderation::restrictions(''), 'the address\'s, everywhere');
    }

    /**
     * A kick is logged and told as `moderation.kicked`, for the realtime layer to close what the
     * subject has open there; it leaves no restriction behind — they may come back.
     */
    #[DataProvider('databases')]
    public function testAKickIsToldAndLeavesNothingBehind(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->rules();
        $nikos  = Subject::account($this->ids['nikos']);
        $kicked = [];
        \Pramnos\Event\Event::listen('moderation.kicked', static function (object $e) use (&$kicked): void {
            $kicked[] = [$e->subject->key(), $e->scope, $e->reason];
        });

        // Act
        Moderation::kick($nikos, 'conversation:1', 'Αρκετά', $this->ids['eleni']);

        // Assert
        $this->assertSame([['account:' . $this->ids['nikos'], 'conversation:1', 'Αρκετά']], $kicked);
        $this->assertSame(['kick', $this->ids['eleni']], [Moderation::history('conversation:1', $nikos)[0]['action'], Moderation::history('conversation:1', $nikos)[0]['by']]);
        $this->assertNull(Moderation::restriction([$nikos], 'conversation:1'));
    }

    /**
     * A report is filed once per reporter and content, with what was said as it was; reports wait
     * until closed, and close only as resolved or dismissed; nobody reports themselves.
     */
    #[DataProvider('databases')]
    public function testReportsWaitToBeClosed(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->rules();
        $nikos = Subject::account($this->ids['nikos']);
        [$a, $b] = [Subject::visitor('reporter-a'), Subject::visitor('reporter-b')];

        // Act
        $first  = Reports::file($a, $nikos, 'conversation:1', 'message:9', 'κακό μήνυμα', 'Ύβρεις');
        $again  = Reports::file($a, $nikos, 'conversation:1', 'message:9', 'κακό μήνυμα', 'Ύβρεις');
        $second = Reports::file($b, $nikos, 'conversation:1', 'message:9', 'κακό μήνυμα');
        $open   = Reports::open('conversation:1');
        Reports::close($first, 'dismissed', $this->ids['eleni']);
        $left   = array_column(Reports::open(), 'id');
        $errors = [];
        foreach ([fn () => Reports::close($second, 'deleted'), fn () => Reports::close(999999, 'resolved'), fn () => Reports::file($a, $a, '', 'x', 'y')] as $bad) {
            try {
                $bad();
            } catch (\InvalidArgumentException | \DomainException $e) {
                $errors[] = $e::class;
            }
        }

        // Assert
        $this->assertSame($first, $again, 'the same report is the first');
        $this->assertCount(2, $open);
        $this->assertNull(Moderation::restriction([$nikos], 'conversation:1'), 'a report restricts nobody by itself');
        $this->assertSame(['κακό μήνυμα', 'message:9', 'visitor'], [$open[0]['snapshot'], $open[0]['content'], $open[0]['reporterKind']]);
        $this->assertSame([$second], $left);
        $this->assertSame([\InvalidArgumentException::class, \DomainException::class, \InvalidArgumentException::class], $errors);
        $this->assertSame('resolve', Moderation::history(null, $nikos)[0]['action']);
    }

    /**
     * What a message meets: a mute refuses it with when it ends; a ban on the writer's address
     * refuses them wherever they write; the scope's slow mode and the rate refuse with how long to
     * wait; the filter masks or refuses — and its refusals and the rate's are in the log.
     */
    #[DataProvider('databases')]
    public function testTheGate(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->rules(['rate' => ['messages' => 3, 'per' => 3600], 'filter' => ['words' => ['βλάκας']]]);
        $scope = 'room:' . bin2hex(random_bytes(4));
        [$slowpoke, $fast, $rude] = [$this->stranger(), $this->stranger(), $this->stranger()];
        $ip   = Subject::ip('198.51.100.' . random_int(1, 254));
        $nick = Subject::nickname('Ρόδι' . random_int(1, 99999));
        Moderation::mute($slowpoke, $scope, 120, 'Ηρέμησε');
        $longer = $this->stranger();
        Moderation::mute($longer, $scope, 7200);

        // Act
        $muted = Gate::check([$slowpoke], $scope, 'γεια');
        $hours = Gate::check([$longer], $scope, 'γεια');
        $nobody = Moderation::restriction(['not a subject'], $scope);
        Moderation::slowMode($scope, 30);
        $slow  = [Gate::check([$fast], $scope, 'ένα')['allowed'], Gate::check([$fast], $scope, 'δύο')];
        Moderation::slowMode($scope, 0);
        $rate  = [Gate::check([$fast], $scope, 'τρία')['allowed'], Gate::check([$fast], $scope, 'τέσσερα')['allowed'], Gate::check([$fast], $scope, 'πέντε')];
        $mask  = Gate::check([$rude, $ip, $nick], $scope, 'είσαι βλάκας');
        $markup = Gate::check([$rude, $ip, $nick], $scope, '<script>x</script>');
        Moderation::ban($ip, '', 3600, 'Spam');
        $after = Gate::check([$this->stranger(), $ip], 'elsewhere', 'γεια');
        $refusals = array_column(Moderation::history($scope), 'reason');

        // Assert
        $this->assertSame(['muted', 120], [$muted['reason'], $muted['retryAfter']]);
        $this->assertStringContainsString('2 h', $hours['message'], 'a wait in hours');
        $this->assertNull($nobody, 'nobody asked about, nothing in force');
        $this->assertTrue($slow[0]);
        $this->assertSame(['slow', 30], [$slow[1]['reason'], $slow[1]['retryAfter']]);
        $this->assertSame([true, true, 'rate'], [$rate[0], $rate[1], $rate[2]['reason']], 'three in the hour, the slow-mode refusal not counted');
        $this->assertGreaterThan(0, $rate[2]['retryAfter']);
        $this->assertSame([true, 'είσαι ******'], [$mask['allowed'], $mask['text']]);
        $this->assertSame('filter', $markup['reason']);
        $this->assertSame('banned', $after['reason'], 'the address, everywhere');
        $this->assertSame(['filter', 'rate'], array_values(array_intersect($refusals, ['filter', 'rate'])), 'newest first');
        $this->assertNotSame('', $muted['message']);
    }

    /**
     * The conversations with the `moderation` feature: a muted member's message and edit are refused
     * with the reason; a message is filtered; history brought in is not moderated; and without the
     * feature nothing of it applies.
     */
    #[DataProvider('databases')]
    public function testTheConversationsAreModerated(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $this->rules(['filter' => ['words' => ['βλάκας']]]);
        $general = Conversations::create('channel', 'general', [$maria, $nikos], $eleni);
        $before  = Messages::post($general, $maria, 'βλάκας χωρίς έλεγχο');
        $this->rules(['filter' => ['words' => ['βλάκας']]], true);
        Moderation::mute(Subject::account($maria), 'conversation:' . $general, 300);
        $refused = [];

        // Act
        foreach ([fn () => Messages::post($general, $maria, 'γεια'), fn () => Messages::edit($before, $maria, 'άλλο'),
            fn () => Messages::post($general, $nikos, '<iframe src=x>'), fn () => Messages::edit($before, $nikos, 'όχι δικό του')] as $try) {
            try {
                $try();
            } catch (\InvalidArgumentException $e) {
                $refused[] = $e->getMessage();
            }
        }
        $masked = Messages::post($general, $nikos, 'κι εσύ βλάκας');
        Messages::edit($masked, $nikos, 'βλάκας ξανά');
        try {
            Messages::edit($masked, $nikos, '<script>x</script>');
            $markupEdit = null;
        } catch (\InvalidArgumentException $e) {
            $markupEdit = $e->getMessage();
        }
        $quiet  = Messages::post($general, $maria, 'από αλλού', ['quiet' => true]);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.77';
        Moderation::ban(Subject::ip('192.0.2.77'), '', 60);
        try {
            Messages::post($general, $nikos, 'από τη διεύθυνση');
            $fromAddress = null;
        } catch (\InvalidArgumentException $e) {
            $fromAddress = $e->getMessage();
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }

        // Assert
        $this->assertSame('βλάκας χωρίς έλεγχο', Messages::get($before)['body'], 'without the feature, as written');
        $this->assertCount(4, $refused, 'muted: no message, no edit; markup refused; and somebody else\'s message is still not theirs to edit');
        $this->assertSame('****** ξανά', Messages::get($masked)['body'], 'an edit is filtered too');
        $this->assertNotNull($markupEdit, 'and refused for markup');
        $this->assertNotNull($fromAddress, 'a request from a banned address is refused, whoever signed in');
        $this->assertSame('από αλλού', Messages::get($quiet)['body'], 'history brought in is not moderated');
    }

    /**
     * The moderators' commands: only an owner or whoever has the ability runs them; each does what
     * it says and answers the moderator; a target is named with @, never oneself; slow mode takes a
     * duration or `off`.
     */
    #[DataProvider('databases')]
    public function testTheModeratorsCommands(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $this->rules([], true);
        ModeratorCommands::register();
        $general = Conversations::create('channel', 'general', [$maria, $nikos], $eleni);
        $run     = fn (string $who, string $text): string => Commands::run($text, $general, $this->user($who))['text'];
        $scope   = 'conversation:' . $general;
        $m       = '<@' . $maria . '>';

        // Act
        $answers = [
            'not'    => $run('nikos', '/mute ' . $m),
            'mute'   => $run('eleni', '/mute ' . $m . ' 5m φασαρία'),
            'unmute' => $run('eleni', '/unmute ' . $m),
            'again'  => $run('eleni', '/unmute ' . $m),
            'ban'    => $run('eleni', '/ban ' . $m . ' 1d'),
            'unban'  => $run('eleni', '/unban ' . $m),
            'never'  => $run('eleni', '/unban ' . $m),
            'forgood' => $run('eleni', '/ban ' . $m),
            'kick'   => $run('eleni', '/kick <@' . $nikos . '> αρκετά'),
            'slow'   => $run('eleni', '/slow 30s'),
            'off'    => $run('eleni', '/slow off'),
            'badslow' => $run('eleni', '/slow sometimes'),
            'nobody' => $run('eleni', '/mute Μαρία'),
            'self'   => $run('eleni', '/mute <@' . $eleni . '>'),
        ];
        $muteLog = array_values(array_filter(Moderation::history($scope, Subject::account($maria)), static fn (array $h): bool => $h['action'] === 'mute'))[0] ?? [];

        // Assert
        $this->assertSame('Only a moderator of this conversation does that.', $answers['not']);
        $this->assertStringContainsString('300', $answers['mute']);
        $this->assertSame(['mute', 'φασαρία', $eleni], [$muteLog['action'] ?? null, $muteLog['reason'] ?? null, $muteLog['by'] ?? null]);
        $this->assertNotSame($answers['unmute'], $answers['again'], 'the second unmute had nothing to lift');
        $this->assertStringContainsString('86400', $answers['ban']);
        $this->assertNotSame($answers['unban'], $answers['never']);
        $this->assertSame('ban', Moderation::restriction([Subject::account($maria)], $scope)['effect'], 'for good');
        $this->assertNull(Moderation::restriction([Subject::account($maria)], $scope)['until']);
        $this->assertSame('kick', Moderation::history($scope, Subject::account($nikos))[0]['action']);
        $this->assertSame(0, Moderation::slowModeOf($scope));
        $this->assertSame(['Say how often: 30s, 2m, or off.', 'Name somebody with @.', 'You cannot moderate yourself.'], [$answers['badslow'], $answers['nobody'], $answers['self']]);
        $this->assertNotSame('', $answers['kick'] . $answers['slow'] . $answers['off'] . $answers['forgood']);
    }
}
