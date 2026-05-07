<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

/**
 * OAuth2 Authentication Middleware
 *
 * Provides helper methods for validating Bearer access tokens in HTTP requests.
 * Not a PSR-15 middleware — works as a standalone service that controllers
 * call directly to protect routes.
 *
 * Usage:
 * ```php
 * $mw = new OAuth2Middleware($controller);
 * $tokenInfo = $mw->validateAccessToken(['read']);
 * if (!$tokenInfo) return; // response already sent
 * ```
 *
 * @package PramnosFramework
 */
class OAuth2Middleware
{
    private OAuth2ServerFactory $factory;

    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->factory = new OAuth2ServerFactory($controller);
    }

    /**
     * Validate the Bearer access token in the current HTTP request.
     *
     * Reads the Authorization header, verifies the token against the database,
     * and optionally checks that the token covers all $requiredScopes.
     * Sends a JSON 401 / 403 response and calls exit() on failure — the caller
     * does not need to handle the response.
     *
     * @param string[] $requiredScopes  Scopes that must all be present on the token.
     * @return array|false              Token row from usertokens on success, false on failure.
     */
    public function validateAccessToken(array $requiredScopes = []): array|false
    {
        try {
            $authHeader = $this->getAuthorizationHeader();

            if (!$authHeader) {
                $this->sendUnauthorized('Missing Authorization header');
                return false;
            }

            if (!preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $this->sendUnauthorized('Invalid Authorization header format');
                return false;
            }

            $tokenInfo = $this->loadTokenFromDatabase($matches[1]);

            if (!$tokenInfo) {
                $this->sendUnauthorized('Invalid or expired access token');
                return false;
            }

            if (!empty($requiredScopes)) {
                $granted = json_decode($tokenInfo['scope'] ?? '[]', true) ?: explode(' ', $tokenInfo['scope'] ?? '');
                foreach ($requiredScopes as $scope) {
                    if (!in_array($scope, $granted, true)) {
                        $this->sendForbidden('Insufficient scope');
                        return false;
                    }
                }
            }

            return $tokenInfo;

        } catch (\Throwable $e) {
            $this->sendUnauthorized('Token validation failed');
            return false;
        }
    }

    /**
     * Revoke an access token by setting status=0 in usertokens.
     */
    public function revokeToken(string $token): bool
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "UPDATE `#PREFIX#usertokens` SET `status` = 0 WHERE `token` = %s AND `tokentype` = 'access_token'",
            $token
        );
        return (bool)$db->query($sql);
    }

    /**
     * Return the userid from the current Bearer token, or null.
     */
    public function getCurrentUserId(): ?int
    {
        $info = $this->validateAccessToken();
        return $info ? (int)$info['userid'] : null;
    }

    /**
     * Return the applicationid from the current Bearer token, or null.
     */
    public function getCurrentApplicationId(): ?int
    {
        $info = $this->validateAccessToken();
        return $info ? (int)$info['applicationid'] : null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function loadTokenFromDatabase(string $token): array|false
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $now = time();

        $sql = $db->prepareQuery(
            "SELECT ut.*, a.name AS client_name"
            . " FROM `#PREFIX#usertokens` ut"
            . " LEFT JOIN `#PREFIX#applications` a ON ut.applicationid = a.appid"
            . " WHERE ut.token = %s AND ut.tokentype = 'access_token'"
            . " AND ut.status = 1 AND (ut.expires = 0 OR ut.expires > %d)",
            $token,
            $now
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            return false;
        }

        $row = $result->fields;

        // Update last-used timestamp without blocking the response.
        $upd = $db->prepareQuery(
            'UPDATE `#PREFIX#usertokens` SET `lastused` = %d WHERE `tokenid` = %d',
            $now,
            (int)$row['tokenid']
        );
        $db->query($upd);

        return $row;
    }

    private function getAuthorizationHeader(): string
    {
        if (!empty($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }
        if (function_exists('apache_request_headers')) {
            $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
            if (!empty($headers['authorization'])) {
                return trim($headers['authorization']);
            }
        }
        return '';
    }

    private function sendUnauthorized(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        header('WWW-Authenticate: Bearer');
        echo json_encode(['error' => 'unauthorized', 'error_description' => $message]);
        exit;
    }

    private function sendForbidden(string $message): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'insufficient_scope', 'error_description' => $message]);
        exit;
    }
}
