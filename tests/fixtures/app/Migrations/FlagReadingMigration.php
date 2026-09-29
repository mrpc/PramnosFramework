<?php

namespace Pramnos\Migrations;

/**
 * Reads var/MAINTENANCE from inside up(), for MaintenanceFlagLifecycleTest.
 *
 * The reason written into the flag is only observable while the migration is
 * running — after runMigration() returns, the file is gone, which is the point
 * of the other tests in that class.
 */
class FlagReadingMigration extends \Pramnos\Database\Migration
{
    public $autoExecute = true;
    public $version = '9.9.7';

    /** @var string What the flag file held during up() */
    public static string $seenDuringUp = '';

    public function up(): void
    {
        $flag = ROOT . DS . 'var' . DS . 'MAINTENANCE';
        self::$seenDuringUp = is_readable($flag)
            ? (string) file_get_contents($flag)
            : '';
    }
}
