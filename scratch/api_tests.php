    public function testLogAction()
    {
        $api = new class extends Api {
            public $apiKey = 'test';
            public function __construct() { }
            public function testLogAction() { $this->logAction(); }
        };
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        \Pramnos\Http\Request::$requestMethod = 'POST';
        $_POST = ['test' => 'data'];
        $api->testLogAction();
        
        \Pramnos\Http\Request::$requestMethod = 'DELETE';
        \Pramnos\Http\Request::$deleteData = ['test' => 'data'];
        $api->testLogAction();

        \Pramnos\Http\Request::$requestMethod = 'PUT';
        \Pramnos\Http\Request::$putData = ['test' => 'data'];
        $api->testLogAction();

        \Pramnos\Http\Request::$requestMethod = 'GET';
        $api->testLogAction();

        $this->assertTrue(true);
    }

    public function testCheckApiKeyWithInvalidKey()
    {
        $api = new Api('');
        $this->assertFalse($api->checkApiKey('invalid_key_1234'));
    }

    public function testRecordTokenAction()
    {
        $api = new class extends Api {
            public function __construct() { }
            public function testRecordTokenAction($start, $resp) { $this->_recordTokenAction($start, $resp); }
        };
        $_SESSION['usertoken'] = new class {
            public $lastActionId = 1;
            public function updateAction($id, $status, $time, $record) {}
        };
        $api->testRecordTokenAction(microtime(true), ['status' => 404]);
        $api->testRecordTokenAction(microtime(true), null);
        $this->assertTrue(true);
    }
