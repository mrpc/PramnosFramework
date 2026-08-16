<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Pramnos\Debug\DebugBar;
use Pramnos\Debug\Collectors\CollectorInterface;

$bar = DebugBar::getInstance();

// 1. SQL / Queries
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'queries'; }
    public function collect(): array {
        return [
            'count'    => 2,
            'cached'   => 0,
            'total_ms' => 10.5,
            'queries'  => [
                ['sql' => 'SELECT * FROM users WHERE status = 1', 'time' => 2.4, 'from_cache' => false],
                ['sql' => 'SELECT * FROM settings LIMIT 1', 'time' => 8.1, 'from_cache' => false]
            ]
        ];
    }
});

// 2. Timers
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'timers'; }
    public function collect(): array {
        return [
            'total_ms' => 24.8,
            'timers' => [
                ['label' => 'Bootstrap', 'ms' => 5.2],
                ['label' => 'Database Query', 'ms' => 10.5],
                ['label' => 'View Render', 'ms' => 9.1]
            ]
        ];
    }
});

// 3. Route
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'route'; }
    public function collect(): array {
        return [
            'uri' => '/demo.php',
            'method' => 'GET',
            'controller' => 'DemoController',
            'action' => 'index'
        ];
    }
});

// 4. Auth
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'auth'; }
    public function collect(): array {
        return ['user' => 'admin@example.com', 'roles' => ['admin']];
    }
});

// 5. Gate
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'gate'; }
    public function collect(): array { return ['checks' => []]; }
});

// 6. Session
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'session'; }
    public function collect(): array { return ['active' => true, 'id' => 'demo_session_123', 'data' => []]; }
});

// 7. Logs
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'logs'; }
    public function collect(): array { return ['count' => 1, 'entries' => [['level' => 'info', 'message' => 'Demo loaded']]]; }
});

// 8. Views
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'views'; }
    public function collect(): array { return ['count' => 1, 'views' => ['demo.html.php']]; }
});

// 9. Domain (Models)
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'models'; }
    public function collect(): array { return ['count' => 2, 'operations' => []]; }
});

// 10. Exceptions
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'exceptions'; }
    public function collect(): array { return ['count' => 0, 'items' => []]; }
});

// 11. Migrations
$bar->addCollector(new class implements CollectorInterface {
    public function name(): string { return 'migrations'; }
    public function collect(): array { return ['ran' => []]; }
});

$debugWidget = $bar->render();

?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pramnos DebugBar Demo Page</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f0f17; color: #cdd6f4; margin: 40px; padding-bottom: 60px; line-height: 1.6; }
        h1 { color: #89b4fa; }
        .card { background: #1e1e2e; border: 1px solid #313244; border-radius: 8px; padding: 24px; max-width: 800px; margin-top: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .badge { background: #89b4fa; color: #11111b; font-weight: bold; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .btn-danger { background: #f38ba8; color: #11111b; border: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 16px; display: inline-block; }
        .btn-danger:hover { background: #f5c2e7; }
        ul { padding-left: 20px; }
        li { margin-bottom: 8px; }
    </style>
</head>
<body>
    <h1>⚙ Pramnos DebugBar Live Demo</h1>
    <p>Δοκιμαστική σελίδα με τις αυτόνομες καρτέλες και τα συμπτυγμένα μενού (App ▾, User ▾, Logs ▾).</p>

    <div class="card">
        <h2>💡 Τι να δοκιμάσετε:</h2>
        <ul>
            <li><strong>Αυτόνομες καρτέλες</strong>: <code>requests</code>, <code>SQL 2</code>, <code>Time 24.8ms</code>, <code>Client</code>, <code>API</code>.</li>
            <li><strong>Συμπτυγμένο `App ▾`</strong>: Περιλαμβάνει τα <code>Route</code>, <code>Domain</code>, <code>Views</code>, <code>Migrations</code>.</li>
            <li><strong>Συμπτυγμένο `User ▾`</strong>: Περιλαμβάνει τα <code>Auth</code>, <code>Gate</code>, <code>Session</code>.</li>
            <li><strong>Συμπτυγμένο `Logs ▾`</strong>: Περιλαμβάνει τα <code>Logs</code>, <code>Exceptions</code>, <code>Errors</code>.</li>
        </ul>

        <hr style="border-color: #313244; margin: 20px 0;">

        <h3>⚡ Αναπαραγωγή JavaScript Error (Δοκιμή Errors):</h3>
        <p>Πατήστε το παρακάτω κουμπί για να προκαλέσετε ένα θελημένο JS error:</p>
        <button class="btn-danger" onclick="triggerTestJsError()">⚡ Προκάλεσε JS Error</button>
    </div>

    <script>
        function triggerTestJsError() {
            // Προκαλεί επίτηδες ReferenceError
            nonExistentFunctionThatWillFail();
        }
    </script>
    <?= $debugWidget ?>
</body>
</html>
