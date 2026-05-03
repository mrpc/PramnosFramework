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
    // All behavior inherited from PostgreSQLGrammar.
    // TimescaleDB-specific compile methods will be added here in Phase 1.3.
}
