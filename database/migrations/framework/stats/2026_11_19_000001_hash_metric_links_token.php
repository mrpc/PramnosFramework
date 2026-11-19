<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * `pramnos.metric_links.token_lookup` — a SHA-256 hash of the bearer token, not the token
 * itself. The column was created as `token`, holding the live credential straight out of the
 * table a DB reader could see (FW-517); a security test added afterwards refuses any `where()`
 * naming a bare `token` column, the same rule `usertokens.token` and `pushlog.report_token`
 * already follow.
 *
 * An installation that already ran `create_metric_links_table` has the column as `token`, with
 * every row's own plaintext bearer value in it: renamed in place and rehashed, rather than
 * adding a second, empty column beside it. A link made before this runs still opens the same
 * report afterwards — the token in its URL hashes to the same value now stored.
 */
class HashMetricLinksToken extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 226;
    public array  $dependencies = ['create_metric_links_table'];
    public $description = 'Renames pramnos.metric_links.token to token_lookup and rehashes its rows, on an installation that already has the old column';

    public function up(): void
    {
        $schema = $this->schema();
        if (!$schema->hasTable('pramnos.metric_links') || $schema->hasColumn('pramnos.metric_links', 'token_lookup')) {
            return;
        }
        if (!$schema->hasColumn('pramnos.metric_links', 'token')) {
            return;
        }

        $schema->alterTable('pramnos.metric_links', function ($table) {
            $table->renameColumn('token', 'token_lookup');
        });

        $result = $this->DB()->queryBuilder()->table('pramnos.metric_links')->get();
        while (($row = $result->fetch()) !== null) {
            $this->DB()->queryBuilder()->table('pramnos.metric_links')
                ->where('token_lookup', $row['token_lookup'])
                ->update(['token_lookup' => hash('sha256', $row['token_lookup'])]);
        }
    }

    public function down(): void
    {
        // The plaintext value cannot be recovered from its hash — nothing to roll back to.
    }
}
