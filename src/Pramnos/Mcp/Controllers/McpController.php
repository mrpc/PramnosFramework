<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Http\Response;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\PublicRegistry;

/**
 * The Model Context Protocol over HTTP, for a caller holding one of this server's access tokens.
 *
 * `McpServer::dispatch()` takes a JSON-RPC message and returns one; `run()` is only a loop reading
 * STDIN around it. So this is a transport and nothing else — it authenticates, decides which tools
 * the caller may see, hands the message to the same dispatcher the stdio server uses, and writes
 * the answer back. There is no second implementation of the protocol here, and there must never
 * be: two implementations of a protocol diverge, and the one nobody runs locally diverges first.
 *
 * ### Refusing is half the job
 *
 * An unauthenticated call gets `401` with a `WWW-Authenticate` header naming
 * `/.well-known/oauth-protected-resource`. That header **is** the discovery mechanism: an MCP
 * client is expected to call blind, be refused, read the document it is pointed at, find the
 * authorization server, and come back with a token. A bare `401` with no header ends the
 * conversation — the client has nowhere to go and a person has to configure it by hand.
 *
 * ### Only what the token allows, and the guarantee is structural
 *
 * The server is built **per request**, holding only the tools {@see PublicRegistry} says these
 * scopes may reach. So a caller cannot invoke a tool by naming it directly: it is not in the
 * server, and `tools/call` answers that it does not know it.
 *
 * That is stronger than filtering the list and checking again on the call, and it fails better.
 * There is one decision instead of two that can disagree, a client that never read the list gains
 * nothing by guessing, and the refusal says *unknown tool* rather than *forbidden* — which does
 * not confirm to a caller that the tool they guessed at exists.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class McpController extends Controller
{
    /** JSON-RPC's own code for "you may not do this". */
    private const RPC_FORBIDDEN = -32001;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['display']);
        parent::__construct($application);
    }

    /**
     * `POST /mcp` — one JSON-RPC message in, one out.
     */
    public function display(): mixed
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            /*
             * A `GET` here is a person opening the endpoint in a browser, or a client probing for
             * the streaming transport this does not implement. Both are better served by being
             * told what this is than by a stack trace or an empty page.
             */
            return Response::json([
                'error'    => 'method_not_allowed',
                'detail'   => 'This endpoint speaks JSON-RPC over POST.',
                'resource' => rtrim(sURL, '/') . '/.well-known/oauth-protected-resource',
            ], 405);
        }

        $user = $this->authenticatedUser();

        if ($user === null) {
            return $this->unauthenticated();
        }

        $message = json_decode((string) file_get_contents('php://input'), true);

        if (!is_array($message)) {
            // -32700 is JSON-RPC's parse error, and the id is null because there is no message to
            // read one from.
            return Response::json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error'],
            ], 400);
        }

        $answer = $this->server($this->scopesOf())->dispatch($message);

        // A notification — a message with no id — gets no reply by specification. `202` says it
        // arrived without inventing a body the client would try to parse.
        return $answer === null
            ? Response::json(null, 202)
            : Response::json($answer);
    }

    /**
     * A server carrying exactly the tools these scopes may reach.
     *
     * Built per request rather than kept: the tools a caller may see depend on the token that
     * arrived with the call, and a server built once and reused would serve the first caller's
     * permissions to the second.
     *
     * @param list<string> $scopes
     */
    protected function server(array $scopes): McpServer
    {
        $server = new McpServer($this->serverName());

        foreach (PublicRegistry::visibleTo($scopes) as $tool) {
            $server->addTool($tool);
        }

        return $server;
    }

    /**
     * The current user, or null when the call carried no usable credential.
     *
     * The bearer token has already been validated by `UnifiedAuthMiddleware` if the route is
     * behind it — this asks the framework who that turned out to be, rather than parsing the
     * header a second time and risking a second opinion.
     */
    protected function authenticatedUser(): ?\Pramnos\User\User
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return ($user && (int) $user->userid > 0) ? $user : null;
    }

    /**
     * The scopes on the token this call arrived with.
     *
     * @return list<string>
     */
    protected function scopesOf(): array
    {
        $token = $_SESSION['usertoken'] ?? null;

        if (!is_object($token) || !isset($token->scope)) {
            /*
             * Authenticated with no readable scope list.
             *
             * Not the same as "no permissions", and not treated as either extreme: an empty list
             * satisfies nothing in `PublicRegistry`, so the caller sees an empty tool list rather
             * than everything. Failing closed here costs a puzzled client; failing open costs
             * whatever the tools do.
             */
            return [];
        }

        $scope = $token->scope;

        return is_array($scope)
            ? array_values(array_map('strval', $scope))
            : array_values(array_filter(explode(' ', (string) $scope)));
    }

    /** What the server calls itself in `initialize`. */
    protected function serverName(): string
    {
        return (string) ($this->application?->applicationInfo['appname'] ?? 'Pramnos');
    }

    /**
     * `401`, and the address of the document that says how to authenticate.
     *
     * RFC 9728 §5.1 defines the `resource_metadata` parameter, and the MCP authorization spec
     * builds its whole discovery flow on it. Without the parameter this is a dead end.
     */
    protected function unauthenticated(): mixed
    {
        $metadata = rtrim(sURL, '/') . '/.well-known/oauth-protected-resource';

        header('WWW-Authenticate: Bearer resource_metadata="' . $metadata . '"');

        return Response::json([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => [
                'code'    => self::RPC_FORBIDDEN,
                'message' => 'Authentication required',
                'data'    => ['resource_metadata' => $metadata],
            ],
        ], 401);
    }
}
