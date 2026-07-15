<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\CapabilitiesSyncService;
use Pramnos\Http\Response;

/**
 * Internal capabilities-push endpoint (feature 2, CI/CD push model).
 *
 * A resource server declares what it exposes by PUTting a JSON capabilities
 * manifest to `/api/internal/clients/{client_id}/capabilities`, authenticating
 * with its own Client Credentials. The manifest is applied by
 * CapabilitiesSyncService (upsert + soft-delete + MD5 short-circuit).
 *
 * Security: the caller may only push its OWN manifest — the authenticated
 * client must match the {client_id} in the path. Credentials are accepted as
 * HTTP Basic (client_id:client_secret) or in the request body.
 *
 * The heavy collaborators (credential source, request body, sync service) are
 * reached through protected methods so the flow is unit-testable without a live
 * HTTP request, mirroring the Oauth controller's testable seams.
 */
class Capabilities extends Controller
{
    use ClientCredentialsAuthTrait;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['sync']);
        parent::__construct($application);
    }

    /**
     * PUT /api/internal/clients/{client_id}/capabilities
     *
     * @param string|null $clientId client_id from the path (validated against
     *                              the authenticated client when present).
     */
    public function sync(?string $clientId = null): mixed
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'PUT' && $method !== 'POST') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        // Authenticate the calling client via its credentials.
        $creds = $this->extractClientCredentials();
        if ($creds === null) {
            return Response::json(
                ['error' => 'invalid_client', 'error_description' => 'Client credentials required'],
                401
            );
        }

        $appId = $this->authenticateClient($creds['client_id'], $creds['client_secret']);
        if ($appId === null) {
            return Response::json(
                ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'],
                401
            );
        }

        // A client may only push its own manifest.
        if ($clientId !== null && $clientId !== '' && $clientId !== $creds['client_id']) {
            return Response::json(
                ['error' => 'forbidden', 'error_description' => 'Cannot push capabilities for another client'],
                403
            );
        }

        // Parse the manifest body.
        $manifest = $this->readManifest();
        if ($manifest === null) {
            return Response::json(
                ['error' => 'invalid_request', 'error_description' => 'Malformed or missing JSON manifest'],
                400
            );
        }

        // Apply it.
        $result = $this->syncService()->sync($appId, $manifest);

        return Response::json($result, 200);
    }

    // ── Testable seams ─────────────────────────────────────────────────────
    // extractClientCredentials() and authenticateClient() come from
    // ClientCredentialsAuthTrait.

    /**
     * Read and decode the JSON manifest from the request body.
     * Returns an array, or null when the body is absent or not valid JSON.
     */
    protected function readManifest(): ?array
    {
        $raw = $this->rawRequestBody();
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** The raw request body (seam so tests can supply a body without php://input). */
    protected function rawRequestBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /** The sync service (seam so tests can inject a double). */
    protected function syncService(): CapabilitiesSyncService
    {
        return new CapabilitiesSyncService(\Pramnos\Framework\Factory::getDatabase());
    }
}
