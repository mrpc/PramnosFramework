<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\ScopedMcpTool;

/**
 * Who is this token, and what may it do?
 *
 * The smallest useful thing an authenticated caller can ask, and the reason it
 * exists is that the public MCP endpoint shipped with **nothing in it**. A
 * transport with no tools cannot be told apart from a transport that is broken:
 * a client that connects, authenticates and receives an empty tool list has no
 * way to know whether the wiring works, the token is right, or the scopes are
 * what somebody intended.
 *
 * So this answers all three, and exposes nothing that the caller did not already
 * present. The identity is the one attached to the token in the request; the
 * scopes are the ones on that token. Somebody holding the token already knows
 * both — what they did not know is whether this server agrees.
 *
 * It is also the first thing to reach for when a tool is «not showing up»: if
 * the scope it needs is missing from this answer, that is the whole diagnosis.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class WhoAmITool implements ScopedMcpTool
{
    public function name(): string
    {
        return 'whoami';
    }

    /**
     * The scope every authenticated MCP caller has.
     *
     * A tool guarded by a scope nobody holds is not a smoke test, and one guarded
     * by nothing at all cannot be registered — `PublicRegistry` refuses a tool
     * with no declared scope, on the grounds that «a tool with no declared scope
     * has no answer to who may call this».
     */
    public function requiredScope(): string
    {
        return 'mcp';
    }

    public function description(): string
    {
        return 'Report the identity and scopes of the token this call arrived with. '
            . 'Use it to check that the MCP endpoint, the token and the scopes are what '
            . 'you think they are.';
    }

    public function inputSchema(): array
    {
        return array('type' => 'object', 'properties' => new \stdClass());
    }

    public function execute(array $input): mixed
    {
        $user  = \Pramnos\User\User::getCurrentUser();
        $token = $_SESSION['usertoken'] ?? null;

        $scopes = array();
        if (is_object($token) && isset($token->scope)) {
            $scopes = is_array($token->scope)
                ? $token->scope
                : array_filter(array_map('trim', explode(' ', (string) $token->scope)));
        }

        return array(
            // No email and no name: the caller knows who they are, and this is a
            // production endpoint whose answers travel. The id is what identifies
            // a row in a log; the rest would be personal data for no purpose.
            'user_id'       => is_object($user) ? (int) ($user->userid ?? 0) : 0,
            'authenticated' => is_object($user) && (int) ($user->userid ?? 0) > 0,
            'scopes'        => array_values($scopes),
            'server'        => 'pramnos-mcp',
        );
    }
}
