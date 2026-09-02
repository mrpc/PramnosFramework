<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Writing a point into a PostGIS column, and the coordinate order that decides where it lands.
 *
 * `insertDataToTable()` and `updateTableData()` each carry the same conversion for a `geometry`
 * column, and neither had ever run. What it does is turn three shapes into PostGIS calls — and the
 * one thing it has to get right is that **`ST_MakePoint()` takes longitude first**.
 *
 * That is the whole of it. `ST_MakePoint(23.7, 37.9)` is Athens; `ST_MakePoint(37.9, 23.7)` is a
 * spot in the Indian Ocean. Nothing errors, every insert succeeds, and the map is wrong — which is
 * exactly the kind of mistake that survives a code review and is found by a customer. A test that
 * only checked "a point was written" would pass on both.
 *
 * Asserted on the SQL rather than through a real insert, deliberately: the conversion is string
 * construction, PostGIS is not installed on either test backend, and a round trip would prove the
 * server accepted the call rather than that the call says what it should.
 */
#[CoversClass(Database::class)]
class GeometryColumnWritesTest extends TestCase
{
    /** A database of a given type that records the SQL it is asked to run. */
    private function recorder(string $type): Database
    {
        $database = new class extends Database {
            /** @var list<string> */
            public array $sqlRun = [];

            public $type      = 'postgresql';
            public $prefix    = '';
            public $connected = true;

            public function __construct() {}

            public function execute($sql, &...$arguments)
            {
                $this->sqlRun[] = (string) $sql;

                return true;
            }

            public function prepareInput($string)
            {
                return str_replace("'", "''", (string) $string);
            }
        };
        $database->type = $type;

        return $database;
    }

    /** The single statement a write produced. */
    private function sqlOf(Database $database): string
    {
        $this->assertCount(1, $database->sqlRun, 'expected exactly one statement');

        return $database->sqlRun[0];
    }

    /**
     * A `"lat, lon"` string becomes a point with **longitude first**.
     *
     * The string is what a form posts — a single field a person pasted coordinates into, in the
     * order everybody writes them. `ST_MakePoint()` wants the other order, and this is where the
     * two conventions meet.
     */
    public function testALatLonStringIsWrittenLongitudeFirst(): void
    {
        // Arrange
        $database = $this->recorder('postgresql');

        // Act
        $database->insertDataToTable('places', [
            ['fieldName' => 'location', 'value' => '37.9838, 23.7275', 'type' => 'geometry'],
        ]);

        // Assert
        $this->assertStringContainsString(
            'ST_SetSRID(ST_MakePoint(23.7275, 37.9838), 4326)',
            $this->sqlOf($database),
            'the coordinates are the wrong way round: this point is in the Indian Ocean'
        );
    }

    /**
     * Negative coordinates survive, in both positions.
     *
     * The western and southern hemispheres are half the world, and the pattern has to accept a
     * leading minus on either number — a regex that did not would silently fall through to
     * `ST_GeomFromText()` and be handed something that is not WKT.
     */
    public function testNegativeCoordinatesAreAccepted(): void
    {
        // Arrange
        $database = $this->recorder('postgresql');

        // Act
        $database->insertDataToTable('places', [
            ['fieldName' => 'location', 'value' => '-33.8688, -151.2093', 'type' => 'geometry'],
        ]);

        // Assert
        $this->assertStringContainsString(
            'ST_MakePoint(-151.2093, -33.8688)',
            $this->sqlOf($database)
        );
    }

    /**
     * Whole numbers with no decimal part are a point too.
     *
     * `0, 0` is a real coordinate — the origin — and a pattern that required a decimal point would
     * send it to `ST_GeomFromText('0, 0')` instead.
     */
    public function testCoordinatesWithNoDecimalPartAreStillAPoint(): void
    {
        // Arrange
        $database = $this->recorder('postgresql');

        // Act
        $database->insertDataToTable('places', [
            ['fieldName' => 'location', 'value' => '0, 0', 'type' => 'geometry'],
        ]);

        // Assert
        $this->assertStringContainsString('ST_MakePoint(0, 0)', $this->sqlOf($database));
    }

    /**
     * Anything that is not a coordinate pair is passed to `ST_GeomFromText()`.
     *
     * Which is what makes the column able to hold more than points: a polygon, a line, a
     * multipoint. WKT is PostGIS's own text format, so handing it through unchanged is the correct
     * answer for every shape the pair-matching does not describe.
     */
    public function testWellKnownTextIsPassedThrough(): void
    {
        // Arrange
        $database = $this->recorder('postgresql');

        // Act
        $database->insertDataToTable('places', [
            [
                'fieldName' => 'area',
                'value'     => 'POLYGON((23.7 37.9, 23.8 37.9, 23.8 38.0, 23.7 37.9))',
                'type'      => 'geometry',
            ],
        ]);

        // Assert
        $this->assertStringContainsString(
            "ST_GeomFromText('POLYGON((23.7 37.9, 23.8 37.9, 23.8 38.0, 23.7 37.9))')",
            $this->sqlOf($database)
        );
    }

    /**
     * A `['latitude' => …, 'longitude' => …]` array is written longitude first as well.
     *
     * The shape an API request arrives in, where the two are named — so the order in the SQL is a
     * decision this code makes rather than one the caller made. Named input is the case where
     * getting it wrong is least excusable and easiest to do.
     */
    public function testANamedLatitudeAndLongitudeArrayIsWrittenLongitudeFirst(): void
    {
        // Arrange
        $database = $this->recorder('postgresql');

        // Act
        $database->insertDataToTable('places', [
            [
                'fieldName' => 'location',
                'value'     => ['latitude' => 37.9838, 'longitude' => 23.7275],
                'type'      => 'geometry',
            ],
        ]);

        // Assert
        $this->assertStringContainsString(
            'ST_SetSRID(ST_MakePoint(23.7275, 37.9838), 4326)',
            $this->sqlOf($database)
        );
    }

    /**
     * `updateTableData()` converts the same three shapes, from its own copy of the code.
     *
     * Its own copy is why this is a separate test and not a parameter: the two methods repeat the
     * conversion, so a correction applied to one would leave every *update* writing points the
     * other way round — and a row that was inserted correctly and then edited would move.
     */
    public function testAnUpdateConvertsGeometryTheSameWay(): void
    {
        // Arrange
        $database = $this->recorder('postgresql');

        // Act
        $database->updateTableData(
            'places',
            [['fieldName' => 'location', 'value' => '37.9838, 23.7275', 'type' => 'geometry']],
            'placeid = 1'
        );

        // Assert
        $sql = $this->sqlOf($database);
        $this->assertStringContainsString('ST_SetSRID(ST_MakePoint(23.7275, 37.9838), 4326)', $sql);
        $this->assertStringContainsString('UPDATE', strtoupper($sql));
    }

    /**
     * An update takes the named array as well.
     *
     * The other branch of the copy, for the same reason.
     */
    public function testAnUpdateTakesTheNamedArrayToo(): void
    {
        // Arrange
        $database = $this->recorder('postgresql');

        // Act
        $database->updateTableData(
            'places',
            [[
                'fieldName' => 'location',
                'value'     => ['latitude' => -33.8688, 'longitude' => 151.2093],
                'type'      => 'geometry',
            ]],
            'placeid = 1'
        );

        // Assert
        $this->assertStringContainsString(
            'ST_MakePoint(151.2093, -33.8688)',
            $this->sqlOf($database)
        );
    }

    /**
     * On MySQL the value is written as it is, with no PostGIS call.
     *
     * `ST_MakePoint` and `ST_SetSRID` are PostGIS; MySQL's spatial functions are named differently
     * and `SRID` is an argument rather than a wrapper. Emitting the PostgreSQL form there would
     * fail on every write, so the conversion is gated on the driver — and this is the assertion
     * that says the gate is on the driver and not on the column type alone.
     */
    public function testOnMysqlNoPostgisCallIsEmitted(): void
    {
        // Arrange
        $database = $this->recorder('mysql');

        // Act
        $database->insertDataToTable('places', [
            ['fieldName' => 'location', 'value' => '37.9838, 23.7275', 'type' => 'geometry'],
        ]);

        // Assert
        $sql = $this->sqlOf($database);
        $this->assertStringNotContainsString('ST_MakePoint', $sql);
        $this->assertStringNotContainsString('ST_SetSRID', $sql);
    }
}
