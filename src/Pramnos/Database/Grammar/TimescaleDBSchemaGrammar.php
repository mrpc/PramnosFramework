<?php

namespace Pramnos\Database\Grammar;

/**
 * TimescaleDB DDL grammar — extends PostgreSQL with future time-series DDL hooks.
 *
 * Currently identical to PostgreSQLSchemaGrammar. Separated so that
 * time_bucket(), hypertable DDL, and continuous-aggregate syntax can be added
 * without touching the base PostgreSQL grammar.
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
class TimescaleDBSchemaGrammar extends PostgreSQLSchemaGrammar
{
    // Future overrides:
    // - compileCreateHypertable()
    // - compileCreateContinuousAggregate()
    // - compileAddRetentionPolicy()
    // - compileAddCompressionPolicy()
}
