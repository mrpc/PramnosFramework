<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * `pramnos.metric_links`: a signed, revocable token that opens one saved report without an
 * account ({@see \Pramnos\Metrics\SharedLinks}) — read with the scope of whoever made it.
 *
 * State, not history (rule 14): a row is the link itself, taken away by `revoked_at` or simply
 * ignored once `expires_at` has passed; nothing here is kept as a record of past opens — that is
 * the audit log's job (`AuditLog::record('metrics.shared_link.opened', ...)`), already written on
 * every open.
 */
class CreateMetricLinksTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 225;
    public array  $dependencies = ['create_pramnos_schema'];
    public $description = 'Creates pramnos.metric_links, signed links that open a saved report without an account';

    public function up(): void
    {
        $schema = $this->schema();
        $schema->ensureSchema('pramnos');
        if ($schema->hasTable('pramnos.metric_links')) {
            return;
        }
        $schema->createTable('pramnos.metric_links', function ($table) {
            $table->comment('Signed links that open a saved report without an account');
            $table->string('token', 64)->primary();
            $table->string('report_id', 100);
            $table->integer('made_by')->unsigned();
            $table->integer('created_at')->unsigned();
            $table->integer('expires_at')->nullable()->unsigned();
            $table->integer('revoked_at')->nullable()->unsigned();
            $table->index(['report_id'], 'idx_metric_links_report');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('pramnos.metric_links');
    }
}
