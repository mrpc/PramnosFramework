<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Records who put a webhook endpoint there: the application, or an administrator.
 *
 * The difference decides how far a delivery is trusted. An endpoint the relying party
 * registered through `/Webhook/register` is an address somebody outside chose, so every
 * delivery to it goes through the user-supplied-URL guard. One an administrator typed on
 * the application's screen is the operator's own statement about their network — a
 * receiver on the VPN, on the same LAN, on this host — and is delivered to as written.
 *
 * ## Default
 *
 * `client`, so every existing row keeps the stricter treatment and nothing is trusted that
 * was not trusted before. An application that re-registers an endpoint an administrator
 * entered turns it back into `client`: the address is theirs again.
 *
 * A plain `varchar` rather than a CHECK-constrained one, because a second application
 * sharing this database writes these rows with its own code and would be refused by a
 * constraint it has never heard of.
 */
class AddRegisteredByToOauth2WebhookEndpoints extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 27;
    public array  $dependencies = ['create_oauth2_webhooks_tables'];
    public $description = 'Adds registered_by (client | admin) to applications.oauth2_webhook_endpoints';

    private const TABLE = 'applications.oauth2_webhook_endpoints';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable(self::TABLE) || $schema->hasColumn(self::TABLE, 'registered_by')) {
            return;
        }

        $schema->alterTable(self::TABLE, function ($table) {
            $table->string('registered_by', 16)->default('client')
                ->comment('Who set the endpoint: client (via /Webhook/register) or admin');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable(self::TABLE) || !$schema->hasColumn(self::TABLE, 'registered_by')) {
            return;
        }

        $schema->alterTable(self::TABLE, function ($table) {
            $table->dropColumn('registered_by');
        });
    }
}
