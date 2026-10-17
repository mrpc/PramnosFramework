<?php

namespace Pramnos\Framework\Migrations\Core;

/**
 * The indexes `add_missing_indexes_to_existing_tables` adds, again — now late in the batch, after
 * the tables they belong to.
 *
 * That migration ran at the default priority, before the `auth` feature created `tokenactions`
 * and `usertokens`; on a fresh installation it found neither, did nothing and was recorded as
 * run. Its priority is fixed for installations to come; this is for the ones it already passed
 * over. Each index is added only where neither its name nor its columns are already indexed, so
 * on an installation that has them it does nothing.
 */
class AddMissingIndexesAfterTheirTables extends \Pramnos\Database\Migrations\AddMissingIndexesToExistingTables
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 200;
    public $description = 'Adds the tokenactions, usertokens, sessions and users indexes a fresh installation was missing';

    /**
     * Nothing: the indexes are the earlier migration's to drop, and this one may have added none.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
