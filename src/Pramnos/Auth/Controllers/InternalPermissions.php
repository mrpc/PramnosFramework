<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\PermissionResolver;
use Pramnos\Auth\PermissionResolverInterface;
use Pramnos\Http\Response;

/**
 * Internal permissions endpoint (feature 6, live fetch).
 *
 * A resource server fetches a user's effective permissions for its own audience
 * via `GET /api/internal/permissions?user_id=…`, authenticating with its own
 * Client Credentials. The authenticated client determines the audience (app_id);
 * an explicit `client_id` query parameter, when present, must match. The result
 * is the PermissionResolver's flat list of effective grants (with ABAC
 * conditions passed through for the caller to evaluate).
 *
 * The resource server caches this locally and re-fetches when a
 * `permissions_changed` webhook invalidates its cache — keeping access tokens
 * lightweight (identity only, never the permission payload).
 *
 * Auth and the resolver are protected seams for unit testing.
 */
class InternalPermissions extends Controller
{
    use ClientCredentialsAuthTrait;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['index']);
        parent::__construct($application);
    }

    /**
     * GET /api/internal/permissions?user_id={id}[&client_id={id}]
     */
    public function index(): mixed
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

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

        // A caller may only read permissions for its own audience.
        $requestedClient = (string) ($_GET['client_id'] ?? '');
        if ($requestedClient !== '' && $requestedClient !== $creds['client_id']) {
            return Response::json(
                ['error' => 'forbidden', 'error_description' => 'Cannot read permissions for another client'],
                403
            );
        }

        $userId = (int) ($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            return Response::json(
                ['error' => 'invalid_request', 'error_description' => 'Missing or invalid user_id'],
                400
            );
        }

        return Response::json($this->resolver()->resolve($userId, $appId), 200);
    }

    /** The permission resolver (seam so tests can inject a double). */
    protected function resolver(): PermissionResolverInterface
    {
        return new PermissionResolver(\Pramnos\Framework\Factory::getDatabase());
    }
}
