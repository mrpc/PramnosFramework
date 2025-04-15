<?php

namespace Pramnos\Geolocation;

use Pramnos\Framework\Base;

/**
 * General geolocation functions
 * @package     PramnosFramework
 * @subpackage  Geolocation
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class General extends Base
{

    /**
     * Factory method
     * @staticvar \Pramnos\Geolocation\General $instance
     * @return \Pramnos\Geolocation\General
     */
    public static function &getInstance()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = new General();
        }

        return $instance;
    }

    /**
     * Calculates the distance between two points (given the latitude/longitude
     * of those points).
     * @param float $firstLatitude    Latitude of point 1 (in decimal degrees)
     * @param float $firstLongitude   Longitude of point 1 (in decimal degrees)
     * @param float $secondLatitude   Latitude of point 2 (in decimal degrees)
     * @param float $secondLongitude  Longitude of point 2 (in decimal degrees)
     * @param string $unit            The unit you desire for results.
     *                                Where: 'M' is statute miles,
     *                                'K' is kilometers (default) and
     *                                'N' is nautical miles
     * @return float Distance between points in the requested unit
     * @throws \InvalidArgumentException When latitude or longitude values are invalid
     * @copyright (c) 2023, Pramnos Hosting
     */
    public function getDistance(
        $firstLatitude,
        $firstLongitude,
        $secondLatitude,
        $secondLongitude,
        $unit = 'K'
    ) {
        // Validate latitude/longitude inputs
        $this->validateCoordinates($firstLatitude, $firstLongitude);
        $this->validateCoordinates($secondLatitude, $secondLongitude);
        
        // Calculate the difference between longitudes
        $theta = $firstLongitude - $secondLongitude;
        
        // Calculate distance using the Haversine formula
        $dist = rad2deg(
            acos(
                min(1.0, sin(deg2rad($firstLatitude)) * sin(deg2rad($secondLatitude)) +
                cos(deg2rad($firstLatitude)) * cos(deg2rad($secondLatitude)) * cos(deg2rad($theta)))
            )
        );
        
        // Convert to miles
        $miles = $dist * 60 * 1.1515;
        
        // Convert to requested unit
        $unit = strtoupper($unit);
        if ($unit === 'K') {
            return ($miles * 1.609344); // Kilometers
        } elseif ($unit === 'N') {
            return ($miles * 0.8684);   // Nautical miles
        } else {
            return $miles;              // Miles
        }
    }
    
    /**
     * Validates latitude and longitude values
     * 
     * @param float $latitude Latitude to validate (-90 to 90)
     * @param float $longitude Longitude to validate (-180 to 180)
     * @throws \InvalidArgumentException When coordinates are invalid
     * @return void
     */
    public function validateCoordinates($latitude, $longitude)
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException("Latitude must be between -90 and 90 degrees");
        }
        
        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException("Longitude must be between -180 and 180 degrees");
        }
    }

}
