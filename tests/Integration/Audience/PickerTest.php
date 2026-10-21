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
}
