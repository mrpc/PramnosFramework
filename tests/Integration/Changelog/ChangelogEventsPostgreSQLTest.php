<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;

/** {@see ChangelogEventsTest} on PostgreSQL, where the changelog tables are hypertables. */
#[CoversClass(\Pramnos\Changelog\ChangelogWriter::class)]
#[CoversClass(\Pramnos\Changelog\ChangelogReader::class)]
#[CoversClass(\Pramnos\Database\WriteSpool::class)]
#[CoversClass(\Pramnos\Database\SchemaBuilder::class)]
final class ChangelogEventsPostgreSQLTest extends ChangelogEventsTest
{
    /** The PostgreSQL container; null skips when it is not reachable. */
    protected function connection(): ?Database
    {
        \Pramnos\Application\Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php');
        $reference = &Database::getInstance();
        $reference = null;
        $db = \Pramnos\Framework\Factory::getDatabase();
        try {
            if (!$db->connected) {
                $db->connect();
            }
        } catch (\Throwable) {
            return null;
        }

        return $db->type === 'postgresql' ? $db : null;
    }
}
