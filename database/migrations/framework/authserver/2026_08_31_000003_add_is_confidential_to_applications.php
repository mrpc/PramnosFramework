<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Lets an application say whether it can keep a secret.
 *
 * OAuth2 divides clients in two. A **confidential** client runs somewhere its
 * operator controls — a server — and can hold a `client_secret`. A **public** one is
 * a single-page app or a mobile binary: whatever secret you ship inside it, every
 * user of it has, so it holds none and authenticates the authorization code with
 * PKCE instead.
 *
 * `Auth\Application::isConfidential()` returned a hardcoded `true`, so the two were
 * indistinguishable and there was nowhere to record the difference. Every client had
 * to have a secret — which, since the token endpoint began requiring one, means a
 * single-page app has to embed one, and an embedded secret is not a secret.
 *
 * ## Why a new column rather than the existing `public` one
 *
 * `applications.public` already exists and means something else: *publicly listed*,
 * 0 = private, 1 = listed. Reading it here would tie whether an application appears
 * in a directory to whether it needs a secret, and the first person to tick "list
 * this app" would silently turn off its client authentication.
 *
 * ## Default
 *
 * `1`, so every existing registration stays confidential and nothing changes for
 * anyone. Being public is opt-in, per application, from the applications screen.
 */
class AddIsConfidentialToApplications extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 71;
    public array  $dependencies = ['create_applications_table'];
    public $description = 'Adds is_confidential to applications (OAuth2 public vs confidential client)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('applications')
            || $schema->hasColumn('applications', 'is_confidential')
        ) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->tinyInteger('is_confidential')->default(1)
                ->comment(
                    'OAuth2 client type: 1 = confidential (holds a client_secret), '
                    . '0 = public (SPA or mobile; PKCE only, no client_credentials)'
                );
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('applications', 'is_confidential')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->dropColumn('is_confidential');
        });
    }
}
