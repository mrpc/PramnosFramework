<?php
namespace Pramnos\Framework\Testing;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use PDO;

/**
 * Base test case for all Pramnos Framework applications.
 * 
 * Provides common setup and helper methods for all test classes,
 * including database interaction, application initialization, and session management.
 */
abstract class BaseTestCase extends TestCase
{
    /**
     * PDO database connection instance.
     * @var PDO|null
     */
    protected $pdo;
    
    /**
     * Main application instance.
     * @var \Pramnos\Application\Application
     */
    protected $application;
    
    /**
     * Database configuration settings.
     * @var array|null
     */
    protected static $dbConfig;

    /**
     * Initialize test environment before each test.
     * 
     * Resets framework singletons, initializes the application, 
     * and sets up session state to prevent cross-test contamination.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up project-specific state if needed
        $_GET = array();
        $_POST = array();
        $_REQUEST = array();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Reset request static state
        $requestClass = '\Pramnos\Http\Request';
        if (class_exists($requestClass)) {
            $request = new $requestClass();
            $request->setAction('');
            $requestClass::$originalRequest = '';
            $requestClass::$requestMethod = 'GET';
        }

        // Initialize application if possible
        try {
            if (class_exists('\Pramnos\Application\Application')) {
                $this->application = \Pramnos\Application\Application::getInstance();
                // Avoid full init in core unit tests if settings missing
                if (defined('APP_PATH') && file_exists(APP_PATH . '/app.php')) {
                    $this->application->init();
                }
            }
        } catch (\Exception $e) {
            // Silently fail for core unit tests
        }

        // Initialize session
        $this->initializeSession();
    }

    /**
     * Get or create a PDO database connection.
     * 
     * Automatically handles both MySQL and PostgreSQL connections and 
     * detects Docker environments for hostname resolution.
     * 
     * @return PDO
     * @throws \PDOException
     */
    protected function getConnection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $config = (array)self::$dbConfig;
        $host = $config['hostname'];
        $port = $config['port'] ?? null;
        $dbName = $config['database'];
        $user = $config['user'];
        $pass = $config['password'];
        $type = $config['type'] ?? 'mysql';

        // Docker detection (assumes standard container names)
        if ($host === 'localhost' && $this->isDocker()) {
            $host = ($type === 'postgresql' || $type === 'pgsql') ? 'postgres' : 'mysql';
        }

        try {
            if ($type === 'postgresql' || $type === 'pgsql') {
                $dsn = "pgsql:host=$host;dbname=$dbName";
                if ($port) $dsn .= ";port=$port";
            } else {
                $dsn = "mysql:host=$host;dbname=$dbName";
                if ($port) $dsn .= ";port=$port";
            }

            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            return $pdo;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Initialize or reset PHP session with test data.
     * 
     * @param array $data Data to populate $_SESSION with
     */
    protected function initializeSession(array $data = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        
        $_SESSION = array_merge([
            'auth' => false,
            'user_id' => null,
            'username' => null,
            'csrf_token' => null
        ], $data);
    }

    /**
     * Simulate a user login by setting session variables.
     * 
     * @param int|string $userId
     */
    protected function loginUser($userId): void
    {
        $this->initializeSession([
            'auth' => true,
            'user_id' => $userId
        ]);
    }

    /**
     * Generate and store a CSRF token in the session.
     * 
     * @return string
     */
    protected function generateCSRFToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Get the current CSRF token from the session.
     * 
     * @return string|null
     */
    protected function getCSRFToken(): ?string
    {
        return $_SESSION['csrf_token'] ?? null;
    }

    /**
     * Populate $_POST with a valid CSRF token from the current session.
     */
    protected function addValidCsrfPostField(): void
    {
        $token = $this->getCSRFToken();

        if (!$token) {
            $token = $this->generateCSRFToken();
        }

        $_POST['csrf_token'] = $token;
    }

    /**
     * Assert that a record exists in the database.
     * 
     * @param string $table Table name
     * @param array  $criteria Column-value pairs
     */
    protected function assertDatabaseHas(string $table, array $criteria): void
    {
        $where = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $where[] = "$column = ?";
            $params[] = $value;
        }
        
        $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $this->assertGreaterThan(0, $stmt->fetchColumn(), "Database table '$table' does not contain matching record.");
    }

    /**
     * Assert that a record does not exist in the database.
     * 
     * @param string $table Table name
     * @param array  $criteria Column-value pairs
     */
    protected function assertDatabaseMissing(string $table, array $criteria): void
    {
        $where = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $where[] = "$column = ?";
            $params[] = $value;
        }
        
        $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $this->assertEquals(0, $stmt->fetchColumn(), "Database table '$table' contains unexpected matching record.");
    }

    /**
     * Detect if running inside a Docker container.
     * 
     * @return bool
     */
    protected function isDocker(): bool
    {
        return file_exists('/.dockerenv');
    }
}
