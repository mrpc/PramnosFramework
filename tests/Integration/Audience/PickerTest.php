<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\Picker;
use Pramnos\Audience\Segments;

/**
 * A send's audience as picked — in any of these segments and groups, except these, a filter for
 * this send, a consent list — compiled to one filter whose counts say what each step took out.
 */
#[CoversClass(Picker::class)]
#[CoversClass(\Pramnos\Messaging\MassMessageAudience::class)]
#[CoversClass(\Pramnos\Messaging\Controllers\MassMessagesController::class)]
class PickerTest extends TestCase
{
    use AudienceProbe;

    /**
     * Included is the union of the picks and the filter; the exceptions and consent each take
     * their share out, and the counts say how many.
     *
     * Greek speakers (anna, cara) or the Press group (bob), except the Volunteers (anna, bob),
     * in the email list that leaves out who unsubscribed from all — cara did.
     */
    #[DataProvider('databases')]
    public function testEachStepTakesItsShareOut(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($greek);
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert(['email' => 'cara@example.com', 'list' => 'all', 'source' => 'page', 'created_at' => time()]);
        $mail  = ConsentLists::create('Service mail', 'email', 'everyone');
        $state = [
            'include' => [['segment' => $greek], ['group' => $this->groups['Press']], ['segment' => (string) $greek]],
            'exclude' => [['group' => $this->groups['Volunteers']]],
            'consent' => (string) $mail,
        ];

        // Act
        $counts = Picker::counts(json_encode($state));
        $final  = Picker::final($state)->ids();
        $mixed  = Picker::counts(['include' => [['group' => $this->groups['Press']]], 'filter' => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'en']]]]);

        // Assert
        $this->assertSame(['included' => 3, 'excluded' => 2, 'consent' => 1, 'final' => 0], $counts);
        $this->assertSame([], $final);
        $this->assertSame(1, $mixed['final'], 'bob: in Press and English');
        $this->assertCount(2, Picker::normalise($state)['include'], 'the same segment twice is one pick');
    }

    /** Nothing picked is everybody in scope; no consent list leaves the audience as it is. */
    #[DataProvider('databases')]
    public function testNothingPickedIsEverybody(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $counts = Picker::counts('');
        $state  = Picker::normalise(['consent' => '0']);

        // Assert
        $this->assertSame(['included' => 3, 'excluded' => 0, 'consent' => 0, 'final' => 3], $counts);
        $this->assertNull($state['consent']);
        $this->assertSame(3, Picker::final(null)->count());
    }

    /** A pick is a segment or a group by id, and the state is JSON: anything else is refused with its place. */
    #[DataProvider('databases')]
    public function testAMalformedPickIsRefused(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $refusals = [];

        // Act
        foreach (['{nope', ['include' => [['tag' => 3]]], ['exclude' => [['segment' => 0]]], ['include' => ['4']]] as $state) {
            try {
                Picker::normalise($state);
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }

        // Assert
        $this->assertSame([
            'The audience is not valid JSON.',
            'include.0: a segment or a group, by id.',
            'exclude.0: a segment or a group, by id.',
            'include.0: a segment or a group, by id.',
        ], $refusals);
    }

    /**
     * A mass message whose criteria are a picked audience reaches exactly what the picker shows,
     * says so in words on its page, and reaches nobody — not everybody — when its state stops
     * checking.
     */
    #[DataProvider('databases')]
    public function testAMassMessageReachesThePickedAudience(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek    = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($greek);
        $news     = ConsentLists::create('Newsletter', 'email', 'everyone');
        $audience = new \Pramnos\Messaging\MassMessageAudience();
        $state    = ['include' => [['segment' => $greek]], 'exclude' => [['group' => $this->groups['Press']]], 'filter' => ['conditions' => [['attribute' => 'usertype', 'op' => 'lt', 'value' => 5]]], 'consent' => $news];

        // Act
        $ids     = $audience->resolve(['audience' => $state]);
        $preview = $audience->preview(['audience' => $state]);
        $words   = \Pramnos\Messaging\MassMessageAudience::describe(['audience' => $state]);
        $plain   = \Pramnos\Messaging\MassMessageAudience::describe(['audience' => ['include' => [['segment' => 999], ['group' => 999]], 'consent' => 999]]);
        $broken  = $audience->resolve(['audience' => ['filter' => ['conditions' => [['attribute' => 'gone', 'op' => 'empty']]]]]);
        // Without the tables to name them from, the words fall back to the ids.
        $this->db->schema()->dropTableIfExists('segments');
        $this->db->schema()->dropTableIfExists('consent_lists');
        $this->probeChanged($this->db->type);
        $bare    = \Pramnos\Messaging\MassMessageAudience::describe(['audience' => ['include' => [['segment' => 3]], 'consent' => 2]]);

        // Assert
        $this->assertSame([$this->ids['anna'], $this->ids['cara']], $ids);
        $this->assertSame(2, $preview['total']);
        $this->assertSame('People in any of Greek; except Press; narrowed by a filter for this send; in the consent list Newsletter.', $words);
        $this->assertSame('People in any of segment #999, group #999; in the consent list #999.', $plain);
        $this->assertSame([], $broken, 'a state that no longer checks reaches nobody');
        $this->assertSame('People in any of #3; in the consent list #2.', $bare);
    }

    /**
     * The compose form stores the picker as the audience when anything is picked, and the
     * account criteria otherwise — an empty picker, or one a hand-made request broke.
     */
    #[DataProvider('databases')]
    public function testTheComposeFormStoresThePickerAsTheAudience(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $controller = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Messaging\Controllers\MassMessagesController {
            /** The criteria the form posted. */
            public function read(array $post): array
            {
                $_POST = $post;
                $_SERVER['REQUEST_METHOD'] = 'POST';

                return $this->criteriaFrom(new \Pramnos\Http\Request());
            }
        };

        // Act
        $picked = $controller->read(['audience' => json_encode(['include' => [['group' => 4]]]), 'language' => 'el']);
        $empty  = $controller->read(['audience' => json_encode(['include' => [], 'filter' => ['match' => 'all', 'conditions' => []]]), 'language' => 'el']);
        $broken = $controller->read(['audience' => '{nope', 'language' => 'el']);
        $_POST  = [];

        // Assert
        $this->assertSame(['audience' => ['include' => [['group' => 4]], 'exclude' => [], 'filter' => ['match' => 'all', 'conditions' => []], 'consent' => null]], $picked);
        $this->assertSame('el', $empty['language'] ?? null);
        $this->assertArrayNotHasKey('audience', $empty);
        $this->assertSame('el', $broken['language'] ?? null);
    }
}
