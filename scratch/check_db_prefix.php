<?php
namespace Pramnos\Database;

require_once 'src/Pramnos/Database/Database.php';
require_once 'src/Pramnos/Database/Result.php';

// Mock connection
class MockDb extends Database {
    public $prefix = 'pr_';
    public function __construct() {
        $this->type = 'mysql';
        $this->connected = true;
    }
    protected function connect() { return true; }
    protected function runQuery($query = "") {
        echo "Executing: " . $query . "\n";
        return true;
    }
    // Override runMysqlQuery to avoid real mysqli calls
    protected function runMysqlQuery($sql, $dieOnFatalError = false, $skipDataFix = false) {
        return $this->runQuery($sql);
    }
}

$db = new MockDb();
$db->query("DELETE FROM #PREFIX#users WHERE userid = 1");
