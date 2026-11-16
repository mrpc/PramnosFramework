<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Attribute;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\Segments;
use Pramnos\Metrics\Store;
use Pramnos\Metrics\Window;

/**
 * BI phase 7h: an organisation's own figure, read from the metrics store as an attribute
 * ({@see Attribute::metric()}), on MySQL (plain and prefixed) and PostgreSQL.
 *
 * What has to hold: the attribute reads the latest kept period's value for the row's own tenant
 * — here each person's own userid stands in for an organisation id, so bob's row of the store is
 * never read for cara — and `trend: true` reads this period's value minus the one before, so a
 * segment can be built on "fell" with the ordinary `lt` operator. A tenant with no row at all
 * reads as empty, never zero.
 */
#[CoversClass(Attribute::class)]
#[CoversClass(Store::class)]
class AttributeMetricTest extends TestCase
{
    use AudienceProbe;

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            Store::forget($this->db, 'test.%');
        }
        parent::tearDown();
    }

    /**
     * August: bob's revenue was 100, September: 80 (fell); cara's August was 50, September 90
     * (rose); anna never has a row at all.
     */
    #[DataProvider('databases')]
    public function testAnOrganisationsFigureIsReadAsAnAttribute(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Store::ensure($this->db);
        $august    = Window::of(Window::MONTH, '2026-08-15');
        $september = Window::of(Window::MONTH, '2026-09-15');
        foreach ([['bob', $august, 100.0], ['bob', $september, 80.0], ['cara', $august, 50.0], ['cara', $september, 90.0]] as [$name, $window, $value]) {
            $row = Store::row($this->db, 'test.revenue', $window, [], $value, 'test', true, time(), null, $this->ids[$name]);
            $this->db->queryBuilder()->table(Store::table('month'))->upsertMany([$row], Store::KEY, ['value']);
        }
        Attributes::define(Attribute::metric('org_revenue', 'users.userid', 'test.revenue', Window::MONTH)->label('Revenue'));
        Attributes::define(Attribute::metric('org_revenue_fell', 'users.userid', 'test.revenue', Window::MONTH, trend: true)->label('Revenue change'));

        // Act
        $fell   = Segments::create('Revenue fell', ['conditions' => [['attribute' => 'org_revenue_fell', 'op' => 'lt', 'value' => 0]]]);
        $latest = Segments::create('Latest at least 90', ['conditions' => [['attribute' => 'org_revenue', 'op' => 'gt', 'value' => 89]]]);
        Segments::recompute($fell);
        Segments::recompute($latest);
        $inFell   = array_column($this->db->queryBuilder()->table('pramnos.segment_members')->where('segmentid', $fell)->whereNull('left_at')->get()->fetchAll(), 'userid');
        $inLatest = array_column($this->db->queryBuilder()->table('pramnos.segment_members')->where('segmentid', $latest)->whereNull('left_at')->get()->fetchAll(), 'userid');

        // Assert
        $this->assertSame([$this->ids['bob']], $inFell, 'only bob\'s revenue fell September against August');
        $this->assertSame([$this->ids['cara']], $inLatest, 'only cara\'s latest kept value is at least 90; bob\'s and anna\'s are not — anna has no row at all');
    }
}
