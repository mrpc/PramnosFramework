<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\TaskPolicy;
use Pramnos\Tests\Integration\Llm\ReportPolicy;

require_once dirname(__DIR__, 2) . '/Integration/Llm/ReportsTest.php';

/**
 * Who may read somebody else's report: never somebody the space's screen is closed to.
 *
 * What holds: in the administrators' space a reader below the analyst's floor — `analyst.floor`,
 * or the administration's default — is refused whatever the report's audience says, even named;
 * at or above it the audience decides as before; in Ask AI there is no floor; the maker always reads
 * their own.
 */
#[CoversClass(TaskPolicy::class)]
class ReaderFloorTest extends LlmTestCase
{
    /** A shared analyst report open to everybody from usertype 10, naming account 5 too. */
    private const TASK = ['userid' => 9, 'owner' => 'global', 'organization_id' => null, 'space' => Scope::ADMIN,
        'audience' => ['min_usertype' => 10, 'users' => [5]]];

    public function testBelowTheFloorNobodyReads(): void
    {
        // Arrange
        $this->configure(['analyst' => ['floor' => 98]]);
        ReportPolicy::$types = [5 => 20, 6 => 98, 7 => 99, 9 => 98];
        $policy = new ReportPolicy(\Pramnos\Llm\Analyst\Analyst::assistant());

        // Act / Assert
        $this->assertSame(98, TaskPolicy::analystFloor());
        $this->assertSame(98, $policy->readerFloor(self::TASK));
        $this->assertFalse($policy->canSee(5, self::TASK), 'named, but below the floor');
        $this->assertTrue($policy->canSee(6, self::TASK), 'at the floor, the audience decides');
        $this->assertTrue($policy->canSee(9, self::TASK), 'the maker reads their own');
        $this->assertSame([9, 7], $policy->audience(self::TASK + ['subscribers' => [5, 7]]), 'the one below is not sent it');
        // Ask AI has no floor
        $this->assertSame(0, $policy->readerFloor(['space' => Scope::CHAT] + self::TASK));
        $this->assertTrue($policy->canSee(5, ['space' => Scope::CHAT] + self::TASK));
        // Without a setting, the administration's default
        $this->configure([]);
        $this->assertSame(\Pramnos\Auth\AdminAccess::defaultUsertype(), TaskPolicy::analystFloor());
    }
}
