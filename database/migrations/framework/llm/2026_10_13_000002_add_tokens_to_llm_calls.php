<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `llm_calls.tokens`, on a table that existed before the framework created it.
 *
 * An application that kept its own call log under this name has every column but this one. It
 * is filled from each row's `usage`, so the summaries count the calls made before it too.
 */
class AddTokensToLlmCalls extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_llm_calls_table'];
    public $description  = 'Adds tokens to llm_calls, filled from usage';

    public function up(): void
    {
        $database = $this->application->database;
        $schema   = $database->schema();

        if (!$schema->hasTable('llm_calls') || $schema->hasColumn('llm_calls', 'tokens')) {
            return;
        }

        $schema->alterTable('llm_calls', function ($table) {
            $table->integer('tokens')->nullable();
        });

        // ponytail: one UPDATE per row with usage — a backfill run once, over a log pruned to 90 days.
        // Every column rather than `usage` by name: it is a reserved word in MySQL, and the
        // builder does not quote a selected column.
        $result = $database->queryBuilder()->table('llm_calls')->get();

        while (($row = $result->fetch()) !== null) {
            $usage = json_decode((string) ($row['usage'] ?? ''), true);

            if (is_array($usage) && $usage !== []) {
                $database->queryBuilder()->table('llm_calls')->where('call_id', (int) $row['call_id'])
                    ->update(['tokens' => \Pramnos\Llm\Pricing::tokensIn($usage)]);
            }
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('llm_calls', 'tokens')) {
            $schema->alterTable('llm_calls', function ($table) {
                $table->dropColumn('tokens');
            });
        }
    }
}
