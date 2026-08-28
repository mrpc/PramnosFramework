<?php

namespace Pramnos\Console;
/**
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Application extends \Symfony\Component\Console\Application
{
    /**
     * Internal application used to connect to databases etc
     * @var \Pramnos\Application\Application
     */
    public $internalApplication = null;

    /**
     * Class Constructor
     * @param string $name Application name
     * @param string $version Application Version
     */
    public function __construct($name = 'Pramnos Framework Console Application',
        $version = '1.0')
    {
        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'localhost';
            $_SERVER['SERVER_PORT'] = 80;
            $_SERVER['SERVER_NAME'] = 'localhost';
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['HTTP_USER_AGENT'] = 'CLI';
            $_SERVER['REQUEST_URI'] = '/';
        }
        // Symfony's DumpCompletionCommand::configure() reads $_SERVER['PHP_SELF']
        // unguarded and passes it to basename(). PHP always populates it on the
        // CLI, but an embedded console application (tests, a queue worker, an
        // HTTP request that shells out) can run with it unset or nulled — which
        // raised an "Undefined array key" warning plus a basename() deprecation
        // the moment registerCommands() ran. Fill it in the same spirit as the
        // web-ish keys above, before parent::__construct() adds the command.
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME']
                ?? $_SERVER['SCRIPT_FILENAME']
                ?? 'pramnos';
        }
        if (!defined('sURL')) {
            define('sURL', 'https://pramnosframework.test'); //MainURL
        }
        parent::__construct($name, $version);
        $this->registerCommands();
        $this->internalApplication
            = \Pramnos\Application\Application::getInstance();

        // Honour app.php's `features` on the CLI too.
        //
        // getInstance() reads app.php into applicationInfo, but the call that
        // turns that list into FeatureRegistry state lives in
        // Application::init() — which the web lifecycle runs and a console
        // command does not. So FeatureRegistry::isEnabled() answered false for
        // every feature inside every command, however app.php was written.
        //
        // That is not a cosmetic gap: a long-running daemon deciding anything
        // from a feature flag reached the opposite conclusion from the web app
        // reading the same file, with nothing anywhere to say the two disagreed.
        // Feature state has to mean one thing per installation, not one thing
        // per entry point.
        if ($this->internalApplication instanceof \Pramnos\Application\Application) {
            \Pramnos\Application\FeatureRegistry::loadFromConfig(
                $this->internalApplication->applicationInfo['features'] ?? []
            );
        }
    }

    /**
     * Register Commands to run
     */
    protected function registerCommands()
    {
        $this->add(new \Pramnos\Console\Commands\Init());
        $this->add(new \Pramnos\Console\Commands\Make\MakeModel());
        $this->add(new \Pramnos\Console\Commands\Make\MakeController());
        $this->add(new \Pramnos\Console\Commands\Make\MakeService());
        $this->add(new \Pramnos\Console\Commands\Make\MakeView());
        // The front-end counterparts of create:view and create:service. Both
        // capabilities existed on MakeCommandBase and neither had a door:
        // createSpaScreen() was reachable only through create:crud, so adding a
        // dashboard meant generating a CRUD for a table nobody wanted and
        // deleting two thirds of it.
        $this->add(new \Pramnos\Console\Commands\Make\MakeScreen());
        $this->add(new \Pramnos\Console\Commands\Make\MakeComponent());
        $this->add(new \Pramnos\Console\Commands\Make\MakeCrud());
        $this->add(new \Pramnos\Console\Commands\Make\MakeApi());
        $this->add(new \Pramnos\Console\Commands\Make\MakeMigration());
        $this->add(new \Pramnos\Console\Commands\Make\MakeMiddleware());
        $this->add(new \Pramnos\Console\Commands\Make\MakeEvent());
        $this->add(new \Pramnos\Console\Commands\Make\MakeListener());
        $this->add(new \Pramnos\Console\Commands\Make\MakeSeeder());
        $this->add(new \Pramnos\Console\Commands\Make\MakeWebhook());
        $this->add(new \Pramnos\Console\Commands\Make\MakeCommand());
        $this->add(new \Pramnos\Console\Commands\Make\MakeTask());
        $this->add(new \Pramnos\Console\Commands\Make\MakeProvider());
        $this->add(new \Pramnos\Console\Commands\Make\MakePolicy());
        $this->add(new \Pramnos\Console\Commands\Make\MakeTest());
        $this->add(new \Pramnos\Console\Commands\Serve());
        // The front-end half of `serve`, for projects with a SPA: the two things
        // done daily were npm commands with no presence in `pramnos list`.
        $this->add(new \Pramnos\Console\Commands\SpaDev());
        $this->add(new \Pramnos\Console\Commands\SpaBuild());
        // Adds a front end to a project that already exists — the case init cannot
        // serve, since it refuses to run where an application is.
        $this->add(new \Pramnos\Console\Commands\ScaffoldSpa());
        // Endpoint functions from the OpenAPI document: the screens stop writing
        // path strings and field names by hand.
        $this->add(new \Pramnos\Console\Commands\Make\MakeApiClient());
        $this->add(new \Pramnos\Console\Commands\Tinker());
        $this->add(new \Pramnos\Console\Commands\MigrateLogs());
        // Migration CLI commands (Phase 4)
        $this->add(new \Pramnos\Console\Commands\Migrate());
        $this->add(new \Pramnos\Console\Commands\MigrateRollback());
        $this->add(new \Pramnos\Console\Commands\MigrateReset());
        $this->add(new \Pramnos\Console\Commands\MigrateRefresh());
        $this->add(new \Pramnos\Console\Commands\MigrateStatus());
        // Health check (Phase 4)
        $this->add(new \Pramnos\Console\Commands\HealthCheck());
        // Routing introspection
        $this->add(new \Pramnos\Console\Commands\RouteList());
        $this->add(new \Pramnos\Console\Commands\ApiDocs());
        // Scheduled tasks (Phase 4)
        $this->add(new \Pramnos\Console\Commands\ScheduleRun());
        $this->add(new \Pramnos\Console\Commands\ScheduleList());
        // The cron-less alternative: one long-running process that runs the
        // same schedule, for containers and anywhere else without a crontab
        $this->add(new \Pramnos\Console\Commands\Work());
        // Policy Engine (Phase 4)
        $this->add(new \Pramnos\Console\Commands\PolicyEngine());
        // Hypertable repair for databases that gained TimescaleDB later
        $this->add(new \Pramnos\Console\Commands\TimescaleEnsure());
        // Writes that were queued because their chunk was already compressed
        $this->add(new \Pramnos\Console\Commands\TimescaleDrain());
        // Writes buffered out of the request path by WriteSpool
        $this->add(new \Pramnos\Console\Commands\SpoolDrain());
        // Queue System (Phase 2)
        $this->add(new \Pramnos\Console\Commands\ProcessQueue());
        $this->add(new \Pramnos\Console\Commands\CleanupQueue());
        $this->add(new \Pramnos\Console\Commands\AuthTokenCleanup());
        $this->add(new \Pramnos\Console\Commands\AuthTwoFactorCleanup());
        $this->add(new \Pramnos\Console\Commands\MessagesDispatch());
        $this->add(new \Pramnos\Console\Commands\AuthTwoFactorStatus());
        $this->add(new \Pramnos\Console\Commands\AuthTwoFactorReset());
        $this->add(new \Pramnos\Console\Commands\AuthWebhookDeliver());
        $this->add(new \Pramnos\Console\Commands\QueueFailed());
        $this->add(new \Pramnos\Console\Commands\QueueRetry());
        // Database seeding + lifecycle
        $this->add(new \Pramnos\Console\Commands\DbSeed());
        $this->add(new \Pramnos\Console\Commands\DbWipe());
        $this->add(new \Pramnos\Console\Commands\DbFresh());
        // User administration + key management
        $this->add(new \Pramnos\Console\Commands\UserCreate());
        $this->add(new \Pramnos\Console\Commands\UserPassword());
        $this->add(new \Pramnos\Console\Commands\KeyGenerate());
        // Scaffolding utilities
        $this->add(new \Pramnos\Console\Commands\ScaffoldViews());
        $this->add(new \Pramnos\Console\Commands\SwitchUi());
        $this->add(new \Pramnos\Console\Commands\LibrariesSync());
        $this->add(new \Pramnos\Console\Commands\ProjectSync());
        $this->add(new \Pramnos\Console\Commands\ProjectResync());
        // Not a scaffolding utility despite living beside them: it writes no project
        // file, it brings a clone's environment up. See its class docblock.
        $this->add(new \Pramnos\Console\Commands\ProjectSetup());
        $this->add(new \Pramnos\Console\Commands\CacheClear());
        $this->add(new \Pramnos\Console\Commands\ThemeBuild());
        $this->add(new \Pramnos\Console\Commands\PageCachePurge());
        // MCP server + debug status (Phase 13)
        $this->add(new \Pramnos\Console\Commands\McpServe());
        $this->add(new \Pramnos\Console\Commands\DebugStatus());
        // Opens the toolbar for one browser on a server where it is off
        $this->add(new \Pramnos\Console\Commands\DebugToken());
        // Lifts a brute-force lockout, so testing a login does not mean waiting
        $this->add(new \Pramnos\Console\Commands\AuthUnlock());
        // Broadcasting (Phase 12)
        $this->add(new \Pramnos\Console\Commands\BroadcastServe());
        // DaemonOrchestrator is abstract — apps register their own concrete subclass
    }

}
