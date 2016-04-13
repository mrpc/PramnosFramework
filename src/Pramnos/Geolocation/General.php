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
     * @param float $SecondLongitude Longitude of point 2 (in decimal degrees)
     * @param string $unit            The unit you desire for results.
     *                                Where: 'M' is statute miles,
     *                                'K' is kilometers (default) and
     *                                'N' is nautical miles
     * @return float
     * @copyright (c) 2015, GeoDataSource.com
     * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPLv3
     */
    public function getDistance($firstLatitude, $firstLongitude,
        $secondLatitude, $SecondLongitude, $unit='K') {
        $theta = $firstLongitude - $SecondLongitude;
        $dist = rad2deg(
            acos(
                sin(deg2rad($firstLatitude))
                * sin(deg2rad($secondLatitude))
                + cos(deg2rad($firstLatitude))
                * cos(deg2rad($secondLatitude))
                * cos(deg2rad($theta))
            )
        );
        $miles = $dist * 60 * 1.1515;
        if (strtoupper($unit) == "K") {
            return ($miles * 1.609344);
        } else if (strtoupper($unit) == "N") {
            return ($miles * 0.8684);
        } else {
            return $miles;
        }
    }


}
