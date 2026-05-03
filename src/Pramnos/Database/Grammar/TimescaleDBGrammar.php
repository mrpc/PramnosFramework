<?php

namespace Pramnos\Database\Grammar;

/**
 * TimescaleDB grammar (extends PostgreSQL).
 *
 * TimescaleDB is a PostgreSQL extension; all DML and DDL syntax is identical.
 * This class exists as a hook point for future TimescaleDB-specific features:
 *   - time_bucket() / time_bucket_gapfill() helpers
 *   - Hypertable DDL (CREATE TABLE ... WITH (tsdb_hypertable))
 *   - Continuous aggregate DDL
 *   - Retention/compression policy helpers
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
class TimescaleDBGrammar extends PostgreSQLGrammar
{
    // -------------------------------------------------------------------------
    // Time-bucket (native TimescaleDB)
    // -------------------------------------------------------------------------

    /**
     * Native time_bucket() call — supports arbitrary intervals including
     * "15 minutes", "6 hours", "7 days", etc.
     *
     * {@inheritdoc}
     */
    public function compileTimeBucket(string $interval, string $column): string
    {
        return "time_bucket('{$interval}', {$column})";
    }
}
