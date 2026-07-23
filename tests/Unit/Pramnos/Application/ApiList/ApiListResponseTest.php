<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\ApiList;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiList\ApiListResponse;

/**
 * Unit tests for the shared list-response envelope builder.
 *
 * WHAT: the four envelope factories (paginated, unpaginated, datatables, error)
 *       that Model::_getApiList() and User::_getApiList() both use.
 * WHY:  these shapes are a contract consumed by API clients and the DataTables
 *       JS plugin, and they were previously duplicated across two classes where
 *       they drifted (the recordsTotal fix had to be applied twice). Centralising
 *       them means the keys, the `draw` echo, the recordsTotal/recordsFiltered
 *       placement and the optional `debug` sub-array are verified in one place.
 */
class ApiListResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        // The datatables envelope reads $_REQUEST['draw']; keep tests isolated.
        unset($_REQUEST['draw']);
    }

    /**
     * paginated() builds the standard envelope with a correct pagination block,
     * and derives hasnext/hasprevious from the page position.
     */
    public function testPaginatedBuildsEnvelopeWithPaginationFlags(): void
    {
        // Arrange / Act — page 2 of 3.
        $r = ApiListResponse::paginated(['row'], 2, 10, 25, 3, ['id', 'name']);

        // Assert — data + fields carried through.
        $this->assertSame(['row'], $r['data']);
        $this->assertSame(['id', 'name'], $r['fields']);
        // Pagination maths: middle page → both neighbours exist.
        $this->assertSame(2, $r['pagination']['currentpage']);
        $this->assertSame(10, $r['pagination']['itemsperpage']);
        $this->assertSame(25, $r['pagination']['totalitems']);
        $this->assertSame(3, $r['pagination']['totalpages']);
        $this->assertTrue($r['pagination']['hasnext'], 'page 2 of 3 has a next page');
        $this->assertTrue($r['pagination']['hasprevious'], 'page 2 of 3 has a previous page');
        // No debug array unless one is supplied.
        $this->assertArrayNotHasKey('debug', $r);
    }

    /**
     * On the first and last page the neighbour flags flip off correctly.
     */
    public function testPaginatedEdgePagesFlipNeighbourFlags(): void
    {
        $first = ApiListResponse::paginated([], 1, 10, 25, 3, []);
        $this->assertFalse($first['pagination']['hasprevious'], 'page 1 has no previous');
        $this->assertTrue($first['pagination']['hasnext']);

        $last = ApiListResponse::paginated([], 3, 10, 25, 3, []);
        $this->assertTrue($last['pagination']['hasprevious']);
        $this->assertFalse($last['pagination']['hasnext'], 'last page has no next');
    }

    /**
     * A supplied $debug array is attached under the `debug` key (Model uses this;
     * the User override passes null to omit it).
     */
    public function testPaginatedIncludesDebugWhenProvided(): void
    {
        $debug = ['filter' => 'where 1=1', 'order' => 'ORDER BY id', 'selectFields' => '`id`'];
        $r = ApiListResponse::paginated([], 1, 10, 0, 1, [], $debug);

        $this->assertArrayHasKey('debug', $r);
        $this->assertSame($debug, $r['debug']);
    }

    /**
     * unpaginated() sets pagination to null and, by default, omits debug.
     */
    public function testUnpaginatedHasNullPaginationAndOptionalDebug(): void
    {
        $withoutDebug = ApiListResponse::unpaginated(['a'], ['id']);
        $this->assertNull($withoutDebug['pagination']);
        $this->assertSame(['a'], $withoutDebug['data']);
        $this->assertArrayNotHasKey('debug', $withoutDebug);

        $withDebug = ApiListResponse::unpaginated(['a'], ['id'], ['order' => 'x']);
        $this->assertArrayHasKey('debug', $withDebug);
    }

    /**
     * datatables() echoes $_REQUEST['draw'] as an int and carries the two record
     * counts, casting them to int.
     */
    public function testDatatablesEchoesDrawAndCastsCounts(): void
    {
        // Arrange — DataTables sends draw as a string.
        $_REQUEST['draw'] = '7';

        // Act — counts supplied as strings to prove the int cast.
        $r = ApiListResponse::datatables([['x']], '50', '3');

        // Assert
        $this->assertSame(7, $r['draw'], 'draw echoed back as int');
        $this->assertSame([['x']], $r['data']);
        $this->assertSame(50, $r['recordsTotal']);
        $this->assertSame(3, $r['recordsFiltered']);
        // The standard envelope keys must NOT leak into the datatables shape.
        $this->assertArrayNotHasKey('pagination', $r);
        $this->assertArrayNotHasKey('fields', $r);
    }

    /**
     * datatables() defaults draw to 0 when the request carries none.
     */
    public function testDatatablesDefaultsDrawToZero(): void
    {
        unset($_REQUEST['draw']);
        $r = ApiListResponse::datatables([], 0, 0);
        $this->assertSame(0, $r['draw']);
    }

    /**
     * error() produces the error envelope: the message, empty data, null
     * pagination, and optional debug.
     */
    public function testErrorEnvelopeShape(): void
    {
        $r = ApiListResponse::error('boom', ['id'], ['filter' => 'bad']);

        $this->assertSame('boom', $r['error']);
        $this->assertSame([], $r['data']);
        $this->assertNull($r['pagination']);
        $this->assertSame(['id'], $r['fields']);
        $this->assertArrayHasKey('debug', $r);

        // Without debug it is omitted.
        $this->assertArrayNotHasKey('debug', ApiListResponse::error('boom', ['id']));
    }
}
