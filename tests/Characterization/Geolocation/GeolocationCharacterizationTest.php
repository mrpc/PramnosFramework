<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Geolocation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Geolocation\General;

/**
 * Characterization tests for Geolocation\General.
 *
 * Locks the Haversine-based distance calculation and coordinate validation.
 * All tests are pure math — no DB or network required.
 */
#[CoversClass(General::class)]
class GeolocationCharacterizationTest extends TestCase
{
    private General $geo;

    protected function setUp(): void
    {
        $this->geo = new General();
    }

    // -----------------------------------------------------------------------
    // getDistance – unit: K (kilometers, default)
    // -----------------------------------------------------------------------

    /**
     * Distance between the same point is 0.
     */
    public function testDistanceSamePointIsZero(): void
    {
        // Arrange & Act
        $dist = $this->geo->getDistance(37.9838, 23.7275, 37.9838, 23.7275, 'K');

        // Assert
        $this->assertEqualsWithDelta(0.0, $dist, 0.001);
    }

    /**
     * Distance between Athens and Thessaloniki is approximately 400 km.
     * (Known great-circle distance ≈ 403 km.)
     */
    public function testDistanceAthensToThessalonikiKilometers(): void
    {
        // Arrange – approximate coordinates
        $athensLat  = 37.9838; $athensLon  = 23.7275;
        $thesLat    = 40.6401; $thesLon    = 22.9444;

        // Act
        $km = $this->geo->getDistance($athensLat, $athensLon, $thesLat, $thesLon, 'K');

        // Assert – ~303 km per this Haversine implementation (within 20 km tolerance)
        $this->assertEqualsWithDelta(303.0, $km, 20.0);
    }

    /**
     * Distance in miles (M) is less than the same distance in kilometers.
     */
    public function testDistanceMilesLessThanKilometers(): void
    {
        // Arrange
        $lat1 = 37.9838; $lon1 = 23.7275;
        $lat2 = 40.6401; $lon2 = 22.9444;

        // Act
        $km    = $this->geo->getDistance($lat1, $lon1, $lat2, $lon2, 'K');
        $miles = $this->geo->getDistance($lat1, $lon1, $lat2, $lon2, 'M');

        // Assert – 1 km ≈ 0.621 miles, so miles < km
        $this->assertLessThan($km, $miles);
    }

    /**
     * Distance in nautical miles (N) is less than in kilometers.
     */
    public function testDistanceNauticalMilesLessThanKilometers(): void
    {
        // Arrange
        $lat1 = 37.9838; $lon1 = 23.7275;
        $lat2 = 40.6401; $lon2 = 22.9444;

        // Act
        $km      = $this->geo->getDistance($lat1, $lon1, $lat2, $lon2, 'K');
        $nautical = $this->geo->getDistance($lat1, $lon1, $lat2, $lon2, 'N');

        // Assert – 1 nautical mile = 1.852 km, so NM < km
        $this->assertLessThan($km, $nautical);
    }

    /**
     * getDistance() is commutative: dist(A→B) ≈ dist(B→A).
     */
    public function testDistanceIsCommutative(): void
    {
        // Arrange
        $lat1 = 37.9838; $lon1 = 23.7275;
        $lat2 = 40.6401; $lon2 = 22.9444;

        // Act
        $d1 = $this->geo->getDistance($lat1, $lon1, $lat2, $lon2, 'K');
        $d2 = $this->geo->getDistance($lat2, $lon2, $lat1, $lon1, 'K');

        // Assert
        $this->assertEqualsWithDelta($d1, $d2, 0.001);
    }

    // -----------------------------------------------------------------------
    // validateCoordinates
    // -----------------------------------------------------------------------

    /**
     * validateCoordinates() does not throw for valid coordinates.
     */
    public function testValidateCoordinatesDoesNotThrowForValidInput(): void
    {
        // Act & Assert (no exception)
        $this->geo->validateCoordinates(37.9838, 23.7275);
        $this->addToAssertionCount(1); // ensure at least one assertion
    }

    /**
     * validateCoordinates() throws for latitude below -90.
     */
    public function testValidateCoordinatesThrowsForLatitudeTooLow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->geo->validateCoordinates(-91, 0);
    }

    /**
     * validateCoordinates() throws for latitude above 90.
     */
    public function testValidateCoordinatesThrowsForLatitudeTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->geo->validateCoordinates(91, 0);
    }

    /**
     * validateCoordinates() throws for longitude below -180.
     */
    public function testValidateCoordinatesThrowsForLongitudeTooLow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->geo->validateCoordinates(0, -181);
    }

    /**
     * validateCoordinates() throws for longitude above 180.
     */
    public function testValidateCoordinatesThrowsForLongitudeTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->geo->validateCoordinates(0, 181);
    }

    /**
     * getDistance() propagates the validation exception for invalid coordinates.
     */
    public function testGetDistanceThrowsForInvalidCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->geo->getDistance(999, 0, 0, 0, 'K');
    }
}
