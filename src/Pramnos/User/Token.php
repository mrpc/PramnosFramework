<?php

namespace Pramnos\User;

/**
 * User tokens
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 *
 * @license    MIT
 */


class Token extends \Pramnos\Framework\Base
{
    /**
     * Web session token — created on web login, accepted by UnifiedAuthMiddleware via session cookie.
     */
    const TYPE_WEB_SESSION = 'web_session';

    /**
     * Standard API / authentication token — issued to first-party or third-party API clients.
     */
    const TYPE_API = 'auth';

    /**
     * OAuth2 Bearer access token.
     */
    const TYPE_ACCESS_TOKEN = 'access_token';

    /**
     * OAuth2 refresh token.
     */
    const TYPE_REFRESH_TOKEN = 'refresh_token';

    /**
     * OAuth2 authorization code.
     */
    const TYPE_AUTH_CODE = 'auth_code';

    /**
     * Apple Push Notification Service device token (push notifications only, not auth).
     */
    const TYPE_APNS = 'apns';

    /**
     * Google Cloud Messaging / Firebase device token (push notifications only, not auth).
     */
    const TYPE_GCM = 'gcm';

    /**
     * Token ID (primary key)
     * @var int
     */
    public $tokenid = 0;
    /**
     * User id of token's owner
     * @var int
     */
    public $userid = null;
    /**
     * Token type
     * auth: Authentication token
     * apns: Apple Push Notification Service token
     * gcm: Google Cloud Messaging token
     * access_token: Access token for OAuth2
     * refresh_token: Refresh token for OAuth2
     * auth_code: Authorization code for OAuth2
     * @var string
     */
    public $tokentype = '';
    /**
     * The actual token
     * @var string
     */
    public $token = '';
    /**
     * When it was created (unix timestamp)
     * @var int
     */
    public $created = 0;
    /**
     * Token notes
     * @var string
     */
    public $notes = '';
    /**
     * When it was last used
     * @var int
     */
    public $lastused = 0;
    /**
     * Token status. 0: inactive 1: active 2: removed - will delete
     * @var int
     */
    public $status = 0;
    /**
     * Parent token (if parent gets deleted, some children will be deleted too)
     * @var int
     */
    public $parentToken = null;
    /**
     * Application ID
     * @var int
     */
    public $applicationid = null;
    /**
     * Actions counter for stats
     * @var int
     */
    public $actions = 0;
    /**
     * When it was removed
     * @var int
     */
    public $removedate = 0;
    /**
     * Device information
     * @var array
     */
    public $deviceinfo = array();
    /**
     * Scope of the token
     * @var array
     */
    public $scope = array();
    /**
     * IP address of the user
     * @var string
     */
    public $ipaddress = '';
    /**
     * When the token expires in unix timestamp`
     * @var int
     */
    public $expires = null;

    /**
     * Token state for database
     * @var bool
     */
    protected $_isnew = true;
    /**
     * Last action ID
     * @var int|null
     */
    public $lastActionId = null;
    /**
     * Last action time in milliseconds
     * @var int|null
     */
    public $lastActionTime = null;

    /**
     * The action recorded by addAction() but not yet written.
     *
     * An API request logs its call and then, once the response is known, logs
     * the status and how long it took. That used to be an INSERT followed by an
     * UPDATE of the same row — two round trips, plus a third for the generated
     * id, all on the request's critical path.
     *
     * Holding the row here until the status is known collapses that into one
     * write, and lets it be buffered rather than paid for while the visitor
     * waits. See {@see flushPendingAction()}.
     *
     * @var array<string, mixed>|null
     */
    protected $pendingAction = null;

    /**
     * Whether a shutdown handler has been registered for this token.
     *
     * @var bool
     */
    protected $pendingActionRegistered = false;

    /**
     * URL ids already resolved, keyed by hash.
     *
     * Filled by the drain rather than by requests, and therefore by a process
     * that lives long enough for it to matter: a site serves a few hundred
     * distinct URLs, and a worker learns them all within minutes.
     *
     * @var array<string, int>
     */
    protected static $urlIdCache = [];

    /**
     * How many URL ids are kept before the oldest are dropped.
     *
     * A worker runs for days. A site that puts an id or a search term in the
     * path generates URLs without limit, and an unbounded cache in a process
     * that never exits is a memory leak with a long fuse.
     */
    const URL_CACHE_LIMIT = 2000;

    /**
     * How often the token row is rewritten just to record that it was used.
     *
     * `lastused` answers "is this token still in use" and `actions` is a
     * counter nobody watches live; a minute's granularity answers both. A
     * change a reader would notice — a new address, a new device — is written
     * at once regardless of this.
     */
    const USE_WRITE_INTERVAL = 60;

    /**
     * What the token looked like the last time its use was written.
     *
     * @var array{ip: string, device: string|false, at: int}|null
     */
    protected $useSnapshot = null;


    /**
     * A user token
     * @param int|array $tokenidOrDataArray
     */
    public function __construct($tokenidOrDataArray = null)
    {
        if ($tokenidOrDataArray !== null) {
            if (
                is_numeric($tokenidOrDataArray)
                || is_string(($tokenidOrDataArray))
            ) {
                $this->load($tokenidOrDataArray);
            } elseif (is_array($tokenidOrDataArray)) {
                $this->fillProperties($tokenidOrDataArray);
            }
        }
        parent::__construct();
    }

    /**
     * Fill properties of the object, based on an array of data
     * @param array $dataArray
     */
    protected function fillProperties($dataArray)
    {
        foreach (array_keys($dataArray) as $field) {
            $this->$field = $dataArray[$field];
        }
        $this->_isnew = false;
        if (\Pramnos\General\Helpers::checkUnserialize($this->deviceinfo)) {
            $this->deviceinfo = unserialize($this->deviceinfo);
        } elseif ($this->deviceinfo && json_decode($this->deviceinfo) !== null) {
            $this->deviceinfo = json_decode($this->deviceinfo, true);
        } else {
            $this->deviceinfo = array();
        }
        if (is_string($this->scope) && json_decode($this->scope) !== null) {
            $this->scope = json_decode($this->scope, true);
        } elseif (is_string($this->scope) && strpos($this->scope, ',') !== false) {
            $this->scope = explode(',', $this->scope);
        } elseif (!is_array($this->scope)) {
            $this->scope = empty($this->scope) ? array() : array($this->scope);
        }
    }

    /**
     * Load a token from the database
     * @param int|string $tokenid
     * @return Token
     */
    public function load($tokenid)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if (is_numeric($tokenid)) {
            $sql = $database->prepareQuery(
                "SELECT * FROM `#PREFIX#usertokens` "
                    . "WHERE `tokenid` = %d limit 1",
                $tokenid
            );
        } else {
            $sql = $database->prepareQuery(
                "SELECT * FROM `#PREFIX#usertokens` "
                    . "WHERE `token` = %s limit 1",
                $tokenid
            );
        }
        $result = $database->query($sql, true, 3600, 'usertokens');
        if ($result->numRows != 0) {
            $this->fillProperties($result->fields);
        }

        return $this;
    }

    /**
     * Returns an array of data
     * @return array
     */
    public function getData()
    {
        $data = array();
        foreach (get_object_vars($this) as $key => $value) {
            if (is_numeric($value) || is_string($value)) {
                $data[$key] = $value;
            }
        }
        $data['created'] = date('c', $this->created);
        if ($data['removedate'] == 0) {
            $data['removedate'] = null;
        } else {
            $data['removedate'] = date('c', $this->removedate);
        }
        if ($data['lastused'] == 0) {
            $data['lastused'] = null;
        } else {
            $data['lastused'] = date('c', $this->lastused);
        }
        $statusArray = array('Inactive', 'Active', 'Deleted');
        $data['status'] = '';
        if (isset($statusArray[(int) $this->status])) {
            $data['status'] = $statusArray[(int) $this->status];
        }
        if ((is_array($this->deviceinfo) && count($this->deviceinfo) > 0)
            || is_object($this->deviceinfo)
        ) {
            $data['deviceinfo'] = $this->deviceinfo;
        }
        return $data;
    }

    /**
     * Add an action to the token log
     */
    public function addAction()
    {
        $this->lastActionTime = (int) (microtime(true) * 1000);
        $request = \Pramnos\Framework\Factory::getRequest();
        [$url, $queryString] = static::splitActionUrl($request->getURL(false));

        \Pramnos\Framework\Factory::getRequest();
        switch (\Pramnos\Http\Request::$requestMethod) {
            case "POST":
                $inputData = json_encode($_POST);
                break;
            case "DELETE":
                $inputData = json_encode(\Pramnos\Http\Request::$deleteData);
                break;
            case "PUT":
                $inputData = json_encode(\Pramnos\Http\Request::$putData);
                break;
            default:
                $inputData = \Pramnos\Http\Request::rawBody();
                break;
        }

        // A GET carries its inputs in the query string, and `params` is where a
        // request's inputs go — it was empty for every GET ever logged. Only
        // when there is nothing else in it: a POST's body is the better record
        // of what it was asked to do.
        if ($queryString !== '' && static::looksEmpty($inputData)) {
            $inputData = $queryString;
        }
        // The row is held rather than written. An API request logs its call and
        // then, once the response is known, logs the status and the duration —
        // which used to be an INSERT, an UPDATE of the same row, and a third
        // round trip for the generated id, all while the visitor waited.
        //
        // Held here, the two become one write, and that write is buffered. The
        // id is therefore not available: nothing needs it any more, because
        // updateAction() completes this array instead of updating a row.
        $this->lastActionId = null;

        // The URL travels as a URL. Turning it into an id means a SELECT
        // against the registry, and doing that here would put back exactly the
        // kind of round trip the buffering removes — on every logged request,
        // to look up a value that never changes.
        //
        // The drain resolves it instead. That process is long-running, so its
        // memory of what it has already resolved is worth far more than a
        // per-request one: a site serves a few hundred distinct URLs, and a
        // worker learns all of them within minutes and then never asks again.
        $this->pendingAction = [
            'tokenid'    => (int) $this->tokenid,
            'url'        => $url,
            'method'     => \Pramnos\Http\Request::$requestMethod,
            'params'     => $inputData,
            'servertime' => time(),
        ];

        $this->registerPendingActionFlush();
        $this->actions += 1;
        $this->lastused = time();
        $remoteip = '';
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $this->deviceinfo = \Pramnos\General\Helpers::getBrowser(
                $_SERVER['HTTP_USER_AGENT']
            );
        }
        // Forwarding headers are only honoured from a configured trusted proxy.
        // A token record whose IP any client can dictate is worse than one that
        // records the proxy, because it looks like evidence.
        $remoteip = \Pramnos\Http\Request::clientIp();
        if ($remoteip != '') {
            $this->ipaddress = $remoteip;
        }

        // The token row is rewritten in full — every column, including the
        // token itself, the device description and the scope — to move
        // `lastused` forward and add one to `actions`. On every request, and
        // twice for a page that then calls its own API.
        //
        // None of that needs to be current to the second: `lastused` answers
        // "is this token still in use", and `actions` is a counter nobody reads
        // in real time. Writing it once a minute answers both questions just as
        // well, and turns a per-request write into an occasional one.
        //
        // Anything that changed something a reader would notice — a new IP, a
        // different device — writes immediately regardless, because those are
        // the fields somebody looks at when a token is doing something
        // unexpected.
        if ($this->shouldPersistTokenUse($remoteip)) {
            $this->save();
        }

        return $this;
    }

    /**
     * Is this token's row worth rewriting on this request?
     *
     * True when something a reader would notice changed — the address or the
     * device — and otherwise only once every {@see USE_WRITE_INTERVAL} seconds.
     *
     * @param  string $remoteip The address this request came from
     * @return bool
     */
    protected function shouldPersistTokenUse($remoteip)
    {
        // A token that has never been written has nothing to compare against.
        if ($this->_isnew || (int) $this->tokenid === 0) {
            return true;
        }

        // A new address or a new device is the thing somebody investigating a
        // stolen token looks at first; it is never delayed.
        if ($this->useSnapshot === null) {
            $this->useSnapshot = [
                'ip'     => (string) $remoteip,
                'device' => json_encode($this->deviceinfo),
                // Now, not zero: an epoch timestamp is older than any interval,
                // so the very next request would decide it was time to write
                // again — making the first two requests both write.
                'at'     => time(),
            ];

            return true;
        }

        if ((string) $remoteip !== $this->useSnapshot['ip']
            || json_encode($this->deviceinfo) !== $this->useSnapshot['device']) {
            $this->useSnapshot['ip']     = (string) $remoteip;
            $this->useSnapshot['device'] = json_encode($this->deviceinfo);
            $this->useSnapshot['at']     = time();

            return true;
        }

        if ((time() - $this->useSnapshot['at']) >= static::USE_WRITE_INTERVAL) {
            $this->useSnapshot['at'] = time();

            return true;
        }

        return false;
    }

    /**
     * Turn a buffered action row into the row the table wants.
     *
     * Registered with {@see \Pramnos\Database\WriteSpool} and run by the
     * drain, never by a request. It swaps the URL the request recorded for the
     * id the column holds.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resolveActionRow(array $row): array
    {
        if (!array_key_exists('url', $row)) {
            return $row;
        }

        $url = (string) $row['url'];
        unset($row['url']);

        $row['urlid'] = static::urlId($url);

        return $row;
    }

    /**
     * The id of a URL in the deduplicated registry, creating it if it is new.
     *
     * Called from the drain, which is a long-running process — so the cache in
     * front of it is the point rather than an optimisation. A site serves a few
     * hundred distinct URLs; a worker learns them in its first minutes and then
     * resolves everything from memory.
     *
     * The cache is bounded, so a site that generates URLs without limit — an id
     * in the path, a search term in the query string — cannot grow it without
     * limit either. When it fills, the oldest half is dropped: the URLs a page
     * actually keeps calling are the ones that were just used, and they survive.
     *
     * @param  string $url
     * @return int    The url id, or 0 when it could not be resolved
     */
    public static function urlId($url)
    {
        $hash = (string) crc32($url);

        if (isset(self::$urlIdCache[$hash])) {
            return self::$urlIdCache[$hash];
        }

        $database = \Pramnos\Framework\Factory::getDatabase();

        try {
            $result = $database->queryBuilder()
                ->table('#PREFIX#urls')
                ->select('urlid')
                ->where('hash', $hash)
                ->limit(1)
                ->get();

            if ($result && $result->numRows > 0) {
                return self::rememberUrlId($hash, (int) $result->fields['urlid']);
            }

            // A URL nobody has logged before. This happens once per distinct
            // URL for the life of the installation.
            $database->queryBuilder()->table('#PREFIX#urls')->insert([
                'url'  => $url,
                'hash' => $hash,
            ]);

            return self::rememberUrlId($hash, (int) $database->getInsertId());
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);

            return 0;
        }
    }

    /**
     * Keep a resolved id, without letting the cache grow without bound.
     *
     * @param  string $hash
     * @param  int    $urlid
     * @return int    The id, so callers can return it directly
     */
    protected static function rememberUrlId($hash, $urlid)
    {
        if (count(self::$urlIdCache) >= self::URL_CACHE_LIMIT) {
            // Drop the oldest half rather than everything: clearing outright
            // would make a worker re-resolve the URLs it is busiest with, over
            // and over, exactly when it is busiest.
            self::$urlIdCache = array_slice(
                self::$urlIdCache,
                (int) (self::URL_CACHE_LIMIT / 2),
                null,
                true
            );
        }

        return self::$urlIdCache[$hash] = $urlid;
    }

    /**
     * Split a request URL into the endpoint and its query string.
     *
     * `urls` is a *deduplicated* registry — one row per endpoint — and it was
     * given the absolute URL including the query, so a page whose query carries
     * an id or a token gets a row of its own every time it is called. Reported
     * from an installation whose "slowest endpoints" report was twenty rows of
     * `…/devpanel/logs?request=<hash>`, one call each: a registry with nothing
     * deduplicated in it and a report with nothing to compare.
     *
     * The scheme and host go too. Every row in an installation has the same
     * one, and where it does not — a multi-tenant application — the endpoint is
     * still the endpoint. An application that needs the host can replace the
     * transformer; {@see \Pramnos\Database\WriteSpool::transform()} is that seam.
     *
     * @param  string $url An absolute request URL
     * @return array{0: string, 1: string} The path, and the query string
     */
    protected static function splitActionUrl(string $url): array
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['path'])) {
            // Not something parse_url() understands: keep it whole rather than
            // lose it. An unparseable URL is still a fact about a request.
            return [$url, ''];
        }

        return [$parts['path'], (string) ($parts['query'] ?? '')];
    }

    /**
     * Whether a params payload carries nothing.
     *
     * `json_encode([])` and an empty body are both "no inputs", and both are
     * what a GET produces.
     *
     * @param  mixed $inputData
     * @return bool
     */
    protected static function looksEmpty($inputData): bool
    {
        $value = trim((string) $inputData);

        return $value === '' || $value === '[]' || $value === '{}' || $value === 'null';
    }

    /**
     * Make sure a held action is written even if nothing completes it.
     *
     * The API path calls updateAction() once it knows the response, and that is
     * what writes the row. The web path never does — so without this, a page
     * view would be logged by being held and then dropped when the process
     * ended.
     *
     * @return void
     */
    protected function registerPendingActionFlush()
    {
        if ($this->pendingActionRegistered) {
            return;
        }

        $this->pendingActionRegistered = true;

        register_shutdown_function(function (): void {
            $this->flushPendingAction();
        });
    }

    /**
     * Write the held action, if there is one.
     *
     * Buffered through {@see \Pramnos\Database\WriteSpool}, which parks the row
     * somewhere cheap and lets the scheduled drain write it. On an installation
     * where nothing can be buffered the spool writes it directly, which is what
     * this method did before it existed.
     *
     * @return void
     */
    public function flushPendingAction()
    {
        if ($this->pendingAction === null) {
            return;
        }

        $row = $this->pendingAction;

        // What this request turned out to cost, for the path that never says.
        //
        // `updateAction()` fills these in and flushes — but only the API path
        // calls it. A web request is written by the shutdown flush instead, and
        // was written without either, so every page view in the audit log had no
        // duration and no status: a "slowest endpoints" report of rows all
        // reading 0.0 ms. Both are knowable here, which is the point of writing
        // at shutdown rather than at the start of the request.
        // array_key_exists, not isset: a null here is a decision — see the
        // negative-status branch of updateAction() — and isset() cannot tell it
        // from a key that was never set.
        if (!array_key_exists('execution_time_ms', $row) && $this->lastActionTime !== null) {
            $row['execution_time_ms'] = round(
                ((float) (microtime(true) * 1000)) - $this->lastActionTime,
                3
            );
        }

        if (!array_key_exists('return_status', $row) && function_exists('http_response_code')) {
            $status = http_response_code();
            if (is_int($status) && $status > 0) {
                $row['return_status'] = $status;
            }
        }

        // Cleared first: a failure to write must not leave a row that a later
        // flush would write a second time.
        $this->pendingAction = null;

        try {
            $this->appendActionRow($row);
        } catch (\Throwable $ex) {
            // Recording that a request happened is not a reason for the request
            // to fail, and by this point it has usually finished anyway.
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    /**
     * Buffer one completed action row.
     *
     * The one line that leaves this class for the spool, so a test can see the
     * row as it is written rather than as it was held — which is the difference
     * the shutdown flush exists to make.
     *
     * @param  array<string, mixed> $row
     * @return void
     */
    protected function appendActionRow(array $row): void
    {
        \Pramnos\Database\WriteSpool::append('#PREFIX#tokenactions', $row);
    }

    /**
     * Complete the action this token logged, with what the response turned out
     * to be.
     *
     * Two shapes, and the signature is unchanged for both:
     *
     * - **The usual one.** `addAction()` held the row; this fills in the status,
     *   the duration and the response, and the completed row is written **once**.
     *   `$actionid` is ignored, because there is no row to identify yet — the
     *   caller passes `$token->lastActionId`, which is null.
     * - **The legacy one.** No row is being held (`addAction()` was not called,
     *   or the row has already been written) and `$actionid` names a real row.
     *   That row is updated exactly as before.
     *
     * @param int $actionid
     * @param int $return_status
     * @param int $execution_time_ms
     * @param mixed $return_data
     */
    public function updateAction($actionid, $return_status, $execution_time_ms = 0, $return_data = null)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($execution_time_ms == 0 && $this->lastActionTime !== null) {
            $execution_time_ms = (float) (microtime(true) * 1000) - $this->lastActionTime;
        }
        
        if ($return_data === null) {
            $return_data = json_encode(array());
        } elseif (is_array($return_data)) {
            $return_data = json_encode($return_data);
        } elseif (is_object($return_data)) {
            $return_data = json_encode(get_object_vars($return_data));
        } elseif (!is_string($return_data)) {
            $return_data = json_encode(array('data' => $return_data));
        }

        // The held row is completed and written here — one write instead of an
        // insert followed by an update of the row it just made.
        if ($this->pendingAction !== null) {
            if ($return_status >= 0) {
                $this->pendingAction['return_status']     = (int) $return_status;
                $this->pendingAction['execution_time_ms'] = round((float) $execution_time_ms, 3);
                $this->pendingAction['return_data']       = $return_data;
            } else {
                // A negative status is the caller saying "this happened, do not
                // record what it returned". Written as explicit nulls rather than
                // left absent, because the shutdown flush fills in what it can
                // see — and "nobody said" and "somebody said no" have to be
                // distinguishable by the time it looks.
                $this->pendingAction['return_status']     = null;
                $this->pendingAction['execution_time_ms'] = null;
            }

            // Written even when the status says not to record one: the request
            // happened, and an audit log that silently omits the calls that
            // ended badly is worse than no audit log.
            $this->flushPendingAction();

            return;
        }

        if ($actionid == 0 || $return_status < 0) {
            return;
        }
        
        $sql = $database->prepareQuery(
            "UPDATE `#PREFIX#tokenactions` SET "
                . "`return_status` = %d, "
                . "`execution_time_ms` = %s, "
                . "`return_data` = %s "
                . "WHERE `actionid` = %d",
            $return_status,
            $execution_time_ms,
            $return_data,
            $actionid
        );
        try {
            $database->query($sql);
        } catch (\Exception $e) {
            if ($database->type == 'postgresql' && strpos($e->getMessage(), 'column "return_status"') !== false) {
                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ADD COLUMN IF NOT EXISTS return_status INTEGER, '
                    . 'ADD COLUMN IF NOT EXISTS execution_time_ms NUMERIC(10,3), '
                    . 'ADD COLUMN IF NOT EXISTS return_data JSONB;'
                ));
                $database->query($database->prepareQuery(
                    'COMMENT ON COLUMN #PREFIX#tokenactions.return_status IS \'HTTP status code returned (200, 404, 500, etc.)\';'
                ));
                $database->query($database->prepareQuery(
                    'COMMENT ON COLUMN #PREFIX#tokenactions.execution_time_ms IS \'Execution time in milliseconds\';'
                ));
                $database->query($database->prepareQuery(
                    'COMMENT ON COLUMN #PREFIX#tokenactions.return_data IS \'JSON response data - use sparingly for debugging/auditing\';'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_return_status ON #PREFIX#tokenactions(return_status);'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_execution_time ON #PREFIX#tokenactions(execution_time_ms);'
                ));

                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ADD COLUMN IF NOT EXISTS action_time TIMESTAMP WITH TIME ZONE;'
                ));

                $database->query($database->prepareQuery(
                    'UPDATE #PREFIX#tokenactions '
                    . 'SET action_time = TO_TIMESTAMP(servertime) '
                    . 'WHERE action_time IS NULL AND servertime IS NOT NULL;'
                ));

                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ALTER COLUMN action_time SET DEFAULT CURRENT_TIMESTAMP;'
                ));

                $database->query($database->prepareQuery(
                    'CREATE OR REPLACE FUNCTION #PREFIX#sync_tokenactions_time() '
                    . 'RETURNS TRIGGER AS $$ '
                    . 'BEGIN '
                    . 'IF NEW.servertime IS NOT NULL THEN '
                    . 'NEW.action_time = TO_TIMESTAMP(NEW.servertime); '
                    . 'ELSE '
                    . 'NEW.action_time = CURRENT_TIMESTAMP; '
                    . 'NEW.servertime = EXTRACT(EPOCH FROM NEW.action_time)::INTEGER; '
                    . 'END IF; '
                    . 'RETURN NEW; '
                    . 'END; '
                    . '$$ LANGUAGE plpgsql;'
                ));

                $database->query($database->prepareQuery(
                    'CREATE TRIGGER sync_tokenactions_time '
                    . 'BEFORE INSERT OR UPDATE ON #PREFIX#tokenactions '
                    . 'FOR EACH ROW EXECUTE FUNCTION #PREFIX#sync_tokenactions_time();'
                ));
                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ALTER COLUMN action_time SET NOT NULL;'
                ));
                try {
                    $database->query($sql);
                } catch (\Exception $e) {
                    // Log the error if the query fails again
                    \Pramnos\Logs\Logger::logError(
                        'Error while updating token action with query: ' 
                        . $sql . ' - Error: ' 
                        . $e->getMessage(),
                        $e
                    );
                    return;
                }
            } elseif ($database->type == 'mysql' && strpos($e->getMessage(), 'Unknown column') !== false) {
                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ADD COLUMN IF NOT EXISTS return_status INT, '
                    . 'ADD COLUMN IF NOT EXISTS execution_time_ms DECIMAL(10,3), '
                    . 'ADD COLUMN IF NOT EXISTS return_data JSON, '
                    . 'ADD COLUMN IF NOT EXISTS action_time TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_return_status ON #PREFIX#tokenactions(return_status);'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_execution_time ON #PREFIX#tokenactions(execution_time_ms);'
                ));
                try {
                    $database->query($sql);
                } catch (\Exception $e) {
                    \Pramnos\Logs\Logger::logError(
                        'Error while updating token action with query: '
                        . $sql . ' - Error: '
                        . $e->getMessage(),
                        $e
                    );
                    return;
                }
            } else {
                // Handle any exceptions that may occur during the query execution
                \Pramnos\Logs\Logger::logError(
                    'Error while updating token action with query: ' 
                    . $sql . ' - Error: ' 
                    . $e->getMessage(),
                    $e
                );
                return;
            }


            
        }
        
    }

    /**
     * Save token to the database
     * @return Token
     */
    public function save()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($this->_isnew == true) {
            $this->added = time();
        }
        if ($this->expires == 0) {
            $this->expires = null;
        }
        $itemdata = array(
            array(
                'fieldName' => 'userid',
                'value' => $this->userid,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'tokentype',
                'value' => $this->tokentype,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'token',
                'value' => $this->token,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'created',
                'value' => $this->created,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'notes',
                'value' => $this->notes,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'lastused',
                'value' => $this->lastused,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'status',
                'value' => $this->status,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'applicationid',
                'value' => $this->applicationid,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'actions',
                'value' => $this->actions,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'removedate',
                'value' => $this->removedate,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'deviceinfo',
                'value' => json_encode($this->deviceinfo),
                'type' => 'string'
            ),
            array(
                'fieldName' => 'scope',
                'value' => is_array($this->scope) ? json_encode($this->scope) : $this->scope,
                'type' => 'string'
            )
        );
        if ($database->type != 'postgresql') {
            $itemdata[] = array(
                'fieldName' => 'parentToken',
                'value' => $this->parentToken,
                'type' => 'integer'
            );
        } else {
            $itemdata[] = array(
                'fieldName' => 'ipaddress',
                'value' => $this->ipaddress,
                'type' => 'string'
            );
            $itemdata[] = array(
                'fieldName' => 'expires',
                'value' => $this->expires,
                'type' => 'integer'
            );
        }
        // Evict cached usertokens reads (Token::load caches by id for 3600s) so
        // status/expiry changes are visible immediately, not after the TTL.
        $database->cacheflush('usertokens');
        if ($this->_isnew == true) {
            $this->_isnew = false;

            if (!$database->insertDataToTable(
                $database->prefix . "usertokens",
                $itemdata
            )) {
                $error = $database->getError();
                $this->addError($error['message']);
            } else {
                $this->tokenid = $database->getInsertId();
            }
            return $this;
        }
        if ((int) $this->tokenid == 0) {
            $this->addError('Token ID is not set');
            return $this;
        }
        if (!$database->updateTableData(
            $database->prefix . "usertokens",
            $itemdata,
            "`tokenid` = '" . (int) $this->tokenid . "'",
            false
        )) {

            if (
                $database->type == 'postgresql'
                && strpos($database->getError()['message'], 'column "ipaddress"') !== false
            ) {

                $database->query($database->prepareQuery('ALTER TABLE #PREFIX#usertokens ADD "expires" integer NULL, ADD "ipaddress" inet NULL;'));
                if (!$database->updateTableData(
                    $database->prefix . "usertokens",
                    $itemdata,
                    "`tokenid` = '" . (int) $this->tokenid . "'",
                    false
                )) {
                    $error = $database->getError();
                    $this->addError($error['message']);
                }
            } else {
                $error = $database->getError();
                $this->addError($error['message']);
            }
        }

        return $this;
    }

    /**
     * Get token details
     * @return array
     */
    public function getDetails()
    {
        if ($this->tokenid == 0) {
            return array(
                'tokenid' => 0,
                'userid' => 0,
                'token' => '',
                'tokentype' => '',
                'lastused' => 0,
                'created' => 0,
                'expires' => 0,
                'scope' => '',
                'status' => 0,
                'applicationid' => 0,
                'deviceinfo' => '',
                'ipaddress' => '',
                'notes' => '',
                'username' => '',
                'firstname' => '',
                'lastname' => '',
                'app_name' => ''
            );
        }
        $db = \Pramnos\Framework\Factory::getDatabase();
        // Get token details
        $tokenQuery = "SELECT ut.tokenid, ut.userid, ut.token, ut.tokentype, ut.lastused, ut.created, 
                       ut.expires, ut.scope, ut.status, ut.applicationid, ut.deviceinfo, ut.ipaddress, ut.notes,
                       u.username, u.firstname, u.lastname, a.name as app_name
                       FROM `#PREFIX#usertokens` ut 
                       LEFT JOIN `#PREFIX#users` u ON ut.userid = u.userid 
                       LEFT JOIN `#PREFIX#applications` a ON ut.applicationid = a.appid
                       WHERE ut.tokenid = %d";

        $tokenQuery = $db->prepareQuery($tokenQuery, $this->tokenid);
        $tokenResult = $db->query($tokenQuery);

        return $tokenResult->fields;
    }

    /**
     * Get token statistics
     * @return array
     */
    public function getStatistics()
    {
        if ($this->tokenid == 0) {
            return array(
                'total_actions' => 0,
                'first_action' => null,
                'last_action' => null,
                'active_days' => 0
            );
        }
        $database = \Pramnos\Framework\Factory::getDatabase();

        // Get statistics
        $statsQuery = "";

        // Use different SQL syntax based on the database type
        if ($database->type == 'postgresql') {
            // PostgreSQL version - use to_timestamp function instead of FROM_UNIXTIME
            $statsQuery = "SELECT 
                            COUNT(*) as total_actions,
                            MIN(servertime) as first_action,
                            MAX(servertime) as last_action,
                            COUNT(DISTINCT DATE(to_timestamp(servertime))) as active_days
                          FROM `#PREFIX#tokenactions` 
                          WHERE tokenid = %d";
        } else {
            // MySQL version - use FROM_UNIXTIME
            $statsQuery = "SELECT 
                            COUNT(*) as total_actions,
                            MIN(servertime) as first_action,
                            MAX(servertime) as last_action,
                            COUNT(DISTINCT DATE(FROM_UNIXTIME(servertime))) as active_days
                          FROM `#PREFIX#tokenactions`
                          WHERE tokenid = %d";
        }

        $statsQuery = $database->prepareQuery($statsQuery, $this->tokenid);
        $statsResult = $database->query($statsQuery);

        return $statsResult->fields;
    }

    /**
     * Get token actions
     * @param int $limit Number of records to return
     * @param int $offset Pagination offset
     * @param string $orderBy Field to order by (default: servertime)
     * @param string $orderDir Order direction (ASC or DESC)
     * @return array With 'data' containing the results and 'total' containing the total count
     */
    public function getActions($limit = 100, $offset = 0, $orderBy = 'servertime', $orderDir = 'DESC')
    {
        if ($this->tokenid == 0) {
            return array('data' => array(), 'total' => 0);
        }
        $database = \Pramnos\Framework\Factory::getDatabase();

        // Validate order direction
        $orderDir = strtoupper($orderDir);
        if ($orderDir !== 'ASC' && $orderDir !== 'DESC') {
            $orderDir = 'DESC';
        }

        // Validate order by field
        $allowedFields = ['actionid', 'tokenid', 'urlid', 'method', 'servertime'];
        if (!in_array($orderBy, $allowedFields)) {
            $orderBy = 'servertime';
        }
        
        // First get total count
        $countQuery = "SELECT COUNT(*) as total FROM `#PREFIX#tokenactions` WHERE tokenid = %d";
        $countQuery = $database->prepareQuery($countQuery, $this->tokenid);
        $countResult = $database->query($countQuery);
        $totalCount = 0;
        if ($countResult && $countResult->numRows > 0) {
            $totalCount = (int)$countResult->fields['total'];
        }
        
        // Get token actions - using database type agnostic query
        $actionsQuery = "SELECT ta.actionid, ta.tokenid, ta.urlid, ta.method, ta.servertime,
                        u.url, ta.params as parameters, ta.return_status,
                        ta.execution_time_ms, ta.return_data
                        FROM `#PREFIX#tokenactions` ta
                        LEFT JOIN `#PREFIX#urls` u ON ta.urlid = u.urlid
                        WHERE ta.tokenid = %d
                        ORDER BY ta." . $orderBy . " " . $orderDir;
                        
        // Add limit and offset in a database-compatible way
        if ($database->type == 'postgresql') {
            $actionsQuery .= " LIMIT %d OFFSET %d";
        } else {
            // MySQL or other databases
            $actionsQuery .= " LIMIT %d OFFSET %d";
        }
        

        $actionsQuery = $database->prepareQuery(
            $actionsQuery, $this->tokenid, $limit, $offset
        );
        
        $actionsResult = $database->query($actionsQuery);

        $actionData = [];

        if ($actionsResult && $actionsResult->numRows > 0) {
            while ($actionsResult->fetch()) {
                $action = $actionsResult->fields;

                // check if $action['parameters'] is a json object, and if it is, decode it
                if (is_string($action['parameters'])) {
                    $action['parameters'] = json_decode($action['parameters'], true);
                }

                $actionData[] = $action;
            }
        }
        return array('data' => $actionData, 'total' => $totalCount);
    }
}
