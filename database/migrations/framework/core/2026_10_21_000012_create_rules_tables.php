<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates rules, rule_versions, rule_runs and rule_log: automations over people — when something
 * happens, who, what in which order, until when — and what each did for each person.
 *
 * A rule keeps its definition as JSON and every saved version beside it, so a run started on
 * version 3 finishes on version 3 while version 4 takes new entrants. `rule_runs` is one person's
 * way through one rule; `rule_log` is every step of it — the trigger, each condition, each branch,
 * each action's outcome — kept for `audience.rule_log_days`. Times are Unix seconds.
 */
class CreateRulesTables extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 245;
    public $description = 'Creates rules, rule_versions, rule_runs and rule_log, the automations over people';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('rules')) {
            $schema->createTable('rules', function ($table) {
                $table->comment('Automations over people: when, who, what, until — the current definition');
                $table->increments('ruleid')->comment('Auto-increment rule identifier');
                $table->string('name', 150)->comment('Shown in the rules list and in every message it sends');
                $table->string('status', 16)->default('draft')->comment('draft, shadow (runs, sends nothing), active or paused');
                $table->integer('version')->default(1)->comment('The current version in rule_versions');
                $table->text('definition')->comment('The current version as JSON — see Pramnos\\Audience\\Rules\\Definition');
                $table->bigInteger('owner')->nullable()->comment('users.userid of who made it');
                $table->unsignedInteger('createdat')->comment('Unix time it was made');
                $table->unsignedInteger('updatedat')->comment('Unix time it was last changed');
            });
        }

        if (!$schema->hasTable('rule_versions')) {
            $schema->createTable('rule_versions', function ($table) {
                $table->comment('Every saved version of every rule');
                $table->bigIncrements('id')->comment('Auto-increment row identifier');
                $table->integer('ruleid')->unsigned()->comment('rules.ruleid');
                $table->integer('version')->comment('1, 2, 3… per rule');
                $table->text('definition')->comment('That version as JSON');
                $table->bigInteger('createdby')->nullable()->comment('users.userid of who saved it');
                $table->unsignedInteger('createdat')->comment('Unix time it was saved');
                $table->unique(['ruleid', 'version'], 'uq_rule_versions');
            });
        }

        if (!$schema->hasTable('rule_runs')) {
            $schema->createTable('rule_runs', function ($table) {
                $table->comment('One person on their way through one rule');
                $table->bigIncrements('runid')->comment('Auto-increment run identifier');
                $table->integer('ruleid')->unsigned()->comment('rules.ruleid');
                $table->integer('version')->comment('The version the run follows to its end');
                $table->bigInteger('personid')->comment('users.userid');
                $table->string('status', 16)->comment('running, waiting, done, exited, goal or failed');
                $table->string('step', 64)->nullable()->comment('The step it is at; NULL when finished');
                $table->integer('seq')->default(0)->comment('How many steps it has taken — what a queued step must match to run');
                $table->string('waitingfor', 100)->nullable()->comment('The event a wait-for-event step listens for');
                $table->string('variant', 32)->nullable()->comment('The A/B variant it was split into');
                $table->tinyInteger('shadow')->default(0)->comment('1 when the rule was in shadow mode: nothing sent');
                $table->text('trigger')->nullable()->comment('What brought the person in, with its causation ids, as JSON');
                $table->unsignedInteger('enteredat')->comment('Unix time the person entered');
                $table->unsignedInteger('updatedat')->comment('Unix time it last moved');
                $table->index(['ruleid', 'personid'], 'idx_rule_runs_person');
                $table->index(['personid', 'waitingfor'], 'idx_rule_runs_waiting');
                $table->index(['ruleid', 'status'], 'idx_rule_runs_status');
            });
        }

        if (!$schema->hasTable('rule_log')) {
            $schema->createTable('rule_log', function ($table) {
                $table->comment('Every step of every run: what was decided, what was done, why');
                $table->bigIncrements('id')->comment('Auto-increment row identifier');
                $table->bigInteger('runid')->comment('rule_runs.runid');
                $table->integer('ruleid')->unsigned()->comment('rules.ruleid');
                $table->integer('version')->comment('The rule version');
                $table->bigInteger('personid')->comment('users.userid');
                $table->string('step', 64)->nullable()->comment('The step, or NULL for entering and leaving');
                $table->string('outcome', 24)->comment('entered, sent, done, skipped, shadow, branch, waiting, resumed, timeout, exited, goal, finished or error');
                $table->string('channel', 16)->nullable()->comment('email, sms, push, site… for a message — what frequency caps count');
                $table->text('detail')->nullable()->comment('Why, as JSON: conditions with their values, the message id, the error');
                $table->unsignedInteger('at')->comment('Unix time');
                $table->index(['runid'], 'idx_rule_log_run');
                $table->index(['ruleid', 'at'], 'idx_rule_log_rule');
                $table->index(['personid', 'outcome', 'at'], 'idx_rule_log_person');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        foreach (['rule_log', 'rule_runs', 'rule_versions', 'rules'] as $table) {
            $schema->dropTableIfExists($table);
        }
    }
}
