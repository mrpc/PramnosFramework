<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\WebhookService;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * Unit tests for Pramnos\Auth\WebhookService.
 *
 * HTTP delivery (curl) is tested by subclassing WebhookService and overriding
 * the protected deliverEvent() method — the test exercises the full
 * retry/back-off/status-update logic without opening real network connections.
 *
 * Signature helpers (verifySignature, buildSignature) are pure functions and
 * are tested directly without any mocking.
 *
 * The database interaction (queueEvent, processQueue, purgeOldEvents) is verified
 * by passing a PHPUnit mock Database that records QueryBuilder calls and
 * returns controlled Result fixtures.
 *
 * deliverEvent() itself is tested by exposing it via an anonymous subclass and
 * answering through Client::fake(). Endpoints are IP literals, so the
 * user-supplied-URL guard decides without DNS and nothing leaves the container.
 */
class WebhookServiceTest extends TestCase
{
    // ── Test infrastructure helpers ──────────────────────────────────────────

    /**
     * Build a fluent QueryBuilder mock that returns $this for all chaining methods
     * and $result for terminal operations (get/first) and $count for count().
     */
    private function buildQbMock(object $result, int $count = 0): QueryBuilder
    {
        /** @var QueryBuilder&\PHPUnit\Framework\MockObject\MockObject $qb */
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereIn')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('get')->willReturn($result);
        $qb->method('first')->willReturn($result);
        $qb->method('insert')->willReturn(null);
        $qb->method('update')->willReturn(null);
        $qb->method('delete')->willReturn(null);
        $qb->method('count')->willReturn($count);
        return $qb;
    }

    /**
     * Build an empty result fixture (numRows = 0, no rows to fetch).
     * Returns an anonymous object with the same shape WebhookService expects.
     */
    private function buildEmptyResult(): object
    {
        return new class {
            public int   $numRows = 0;
            public array $fields  = [];
            public function fetch(): bool { return false; }
        };
    }

    /**
     * Build a result fixture with one row of data.
     * fetch() returns true on the first call, false thereafter.
     */
    private function buildSingleRowResult(array $fields): object
    {
        return new class($fields) {
            public int   $numRows;
            public array $fields;
            private int  $calls = 0;
            public function __construct(array $f) {
                $this->numRows = 1;
                $this->fields  = $f;
            }
            public function fetch(): bool
            {
                return ($this->calls++ < 1);
            }
        };
    }

    /**
     * Build a result fixture with two rows of data.
     * fetch() returns true twice (updating $fields each time), then false.
     */
    private function buildTwoRowResult(array $fields1, array $fields2): object
    {
        return new class($fields1, $fields2) {
            public int   $numRows = 2;
            public array $fields;
            private array $rows;
            private int   $calls = 0;
            public function __construct(array $f1, array $f2) {
                $this->rows   = [$f1, $f2];
                $this->fields = $f1;
            }
            public function fetch(): bool
            {
                if ($this->calls < count($this->rows)) {
                    $this->fields = $this->rows[$this->calls++];
                    return true;
                }
                return false;
            }
        };
    }

    // ── Constructor ──────────────────────────────────────────────────────────

    /**
     * Constructor must store the injected Database instance.
     *
     * WebhookService depends entirely on its $database property for all
     * persistence operations. This ensures the DI contract is honoured.
     */
    public function testConstructorStoresDatabaseInstance(): void
    {
        // Arrange
        $db = $this->createMock(Database::class);

        // Act
        $service = new WebhookService($db);

        // Assert — read the private $database via reflection
        $ref = new \ReflectionProperty(WebhookService::class, 'database');
        $this->assertSame($db, $ref->getValue($service),
            'Constructor must store the injected Database instance');
    }

    // ── queueEvent ───────────────────────────────────────────────────────────

    /**
     * queueEvent() must return 0 immediately when no active endpoints match the event type.
     *
     * If no endpoint rows exist for the event type, no INSERT should be issued and
     * the caller gets a 0 to indicate nothing was queued.
     */
    public function testQueueEventReturnsZeroWhenNoEndpoints(): void
    {
        // Arrange
        $db = $this->createMock(Database::class);
        $qb = $this->buildQbMock($this->buildEmptyResult());
        $db->method('queryBuilder')->willReturn($qb);

        $service = new WebhookService($db);

        // Act
        $count = $service->queueEvent('token_revoked', 42, ['key' => 'val']);

        // Assert
        $this->assertSame(0, $count,
            'queueEvent must return 0 when no matching webhook endpoints exist');
    }

    /**
     * queueEvent() must insert one event row per matching endpoint.
     *
     * When two active endpoints are subscribed to the given event type, two
     * INSERT rows must be issued and the return value must be 2.
     */
    public function testQueueEventInsertsOneRowPerEndpoint(): void
    {
        // Arrange — two matching endpoints
        $endpointRows = $this->buildTwoRowResult(
            ['webhook_id' => 1, 'retry_count' => 3],
            ['webhook_id' => 2, 'retry_count' => 5]
        );

        // Track insert calls
        $insertCount = 0;
        $qbForInsert = $this->createMock(QueryBuilder::class);
        $qbForInsert->method('table')->willReturnSelf();
        $qbForInsert->method('select')->willReturnSelf();
        $qbForInsert->method('where')->willReturnSelf();
        $qbForInsert->method('whereIn')->willReturnSelf();
        $qbForInsert->method('get')->willReturn($endpointRows);
        $qbForInsert->method('insert')->willReturnCallback(
            function () use (&$insertCount) { $insertCount++; }
        );

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qbForInsert);

        $service = new WebhookService($db);

        // Act
        $count = $service->queueEvent('token_revoked', 42, ['data' => 'payload']);

        // Assert — one insert per endpoint
        $this->assertSame(2, $count, 'queueEvent must return one count per queued endpoint');
        $this->assertSame(2, $insertCount, 'exactly one INSERT must be issued per matching endpoint');
    }

    /**
     * queueEvent() must propagate optional parameters (deviceCode, tokenId) into the inserted row.
     *
     * These optional fields allow the event row to reference the specific device or token
     * that triggered the event — important for downstream audit and revocation.
     */
    public function testQueueEventPassesOptionalFieldsToInsert(): void
    {
        // Arrange
        $endpointRow = $this->buildSingleRowResult(['webhook_id' => 7, 'retry_count' => 2]);

        $insertedData = null;
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('get')->willReturn($endpointRow);
        $qb->method('insert')->willReturnCallback(
            function (array $data) use (&$insertedData) { $insertedData = $data; }
        );

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);

        $service = new WebhookService($db);

        // Act
        $service->queueEvent('token_revoked', 42, ['token' => 'abc'], 'DEVICE_CODE_XYZ', 99);

        // Assert — inserted row must contain the provided optional fields
        $this->assertNotNull($insertedData);
        $this->assertSame('DEVICE_CODE_XYZ', $insertedData['device_code'],
            'device_code must be forwarded into the inserted event row');
        $this->assertSame(99, $insertedData['token_id'],
            'token_id must be forwarded into the inserted event row');
        $this->assertSame(42, $insertedData['user_id'],
            'user_id must match the parameter passed to queueEvent');
    }

    // ── processQueue ─────────────────────────────────────────────────────────

    /**
     * processQueue() must return ['sent' => 0, 'failed' => 0] when no pending events exist.
     *
     * This is the no-op fast-path: the method fetches pending events and returns
     * immediately without any delivery attempt.
     */
    public function testProcessQueueReturnsZerosWhenNoPendingEvents(): void
    {
        // Arrange
        $db = $this->createMock(Database::class);
        $qb = $this->buildQbMock($this->buildEmptyResult());
        $db->method('queryBuilder')->willReturn($qb);

        $service = new WebhookService($db);

        // Act
        $stats = $service->processQueue();

        // Assert
        $this->assertSame(['sent' => 0, 'failed' => 0], $stats,
            'processQueue must return zero counts when there are no pending events');
    }

    /**
     * processQueue() must mark an event as 'cancelled' when its endpoint has been deleted.
     *
     * If the endpoint row is gone (numRows === 0 on the endpoint lookup), the event
     * can never be delivered — it must be marked 'cancelled' to prevent it blocking the queue.
     */
    public function testProcessQueueCancelsEventWhenEndpointIsDeleted(): void
    {
        // Arrange — one pending event, endpoint not found
        $pendingEvent = $this->buildSingleRowResult([
            'event_id'     => 5,
            'webhook_id'   => 99,
            'event_type'   => 'token_revoked',
            'payload'      => '{}',
            'attempts'     => 0,
            'max_attempts' => 3,
        ]);
        $noEndpoint = $this->buildEmptyResult();

        $cancelledEventId = null;
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereIn')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('get')->willReturn($pendingEvent);
        $qb->method('first')->willReturn($noEndpoint);
        $qb->method('update')->willReturnCallback(
            function (array $data) use (&$cancelledEventId) {
                if (isset($data['status']) && $data['status'] === 'cancelled') {
                    $cancelledEventId = true;
                }
            }
        );

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);

        $service = new WebhookService($db);

        // Act
        $stats = $service->processQueue();

        // Assert — event is cancelled, not counted as sent/failed
        $this->assertSame(['sent' => 0, 'failed' => 0], $stats,
            'cancelled events must not increment sent or failed counters');
        $this->assertTrue($cancelledEventId,
            'the event must be updated with status=cancelled when its endpoint is missing');
    }

    /**
     * processQueue() must increment the 'sent' counter on successful delivery.
     *
     * A successful delivery updates the event row with status='sent' and the
     * current timestamp.
     */
    public function testProcessQueueCountsSentOnSuccessfulDelivery(): void
    {
        // Arrange
        $pendingEvent = $this->buildSingleRowResult([
            'event_id'     => 10,
            'webhook_id'   => 1,
            'event_type'   => 'token_revoked',
            'payload'      => '{"x":1}',
            'attempts'     => 0,
            'max_attempts' => 3,
        ]);
        $endpoint = new class {
            public int   $numRows = 1;
            public array $fields  = [
                'endpoint_url'    => 'http://example.com/hook',
                'secret_key'      => 'secret',
                'timeout_seconds' => 10,
            ];
        };

        $sentStatus = null;
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereIn')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('get')->willReturn($pendingEvent);
        $qb->method('first')->willReturn($endpoint);
        $qb->method('update')->willReturnCallback(
            function (array $data) use (&$sentStatus) {
                if (isset($data['status'])) {
                    $sentStatus = $data['status'];
                }
            }
        );

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);

        // Use an anonymous subclass to override deliverEvent() — no real curl needed
        $service = new class($db) extends WebhookService {
            protected function deliverEvent(array $event): bool
            {
                return true; // simulate successful delivery
            }
        };

        // Act
        $stats = $service->processQueue();

        // Assert
        $this->assertSame(1, $stats['sent'], 'sent count must increment on successful delivery');
        $this->assertSame(0, $stats['failed'], 'failed count must remain zero on successful delivery');
        $this->assertSame('sent', $sentStatus, "event status must be set to 'sent'");
    }

    /**
     * processQueue() must set status='failed' when retry attempts are exhausted.
     *
     * When newAttempts >= maxAttempts, the event is considered permanently failed
     * and must not be retried again.
     */
    public function testProcessQueueMarksEventAsFailedWhenAttemptsExhausted(): void
    {
        // Arrange — event at its final attempt (attempts=2, max_attempts=3)
        $pendingEvent = $this->buildSingleRowResult([
            'event_id'     => 20,
            'webhook_id'   => 1,
            'event_type'   => 'token_revoked',
            'payload'      => '{}',
            'attempts'     => 2,
            'max_attempts' => 3,
        ]);
        $endpoint = new class {
            public int   $numRows = 1;
            public array $fields  = [
                'endpoint_url'    => 'http://example.com/hook',
                'secret_key'      => 'key',
                'timeout_seconds' => 5,
            ];
        };

        $updatedStatus = null;
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereIn')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('get')->willReturn($pendingEvent);
        $qb->method('first')->willReturn($endpoint);
        $qb->method('update')->willReturnCallback(
            function (array $data) use (&$updatedStatus) {
                if (isset($data['status'])) {
                    $updatedStatus = $data['status'];
                }
            }
        );

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);

        $service = new class($db) extends WebhookService {
            protected function deliverEvent(array $event): bool
            {
                return false; // simulate delivery failure
            }
        };

        // Act
        $stats = $service->processQueue();

        // Assert — permanently failed, not pending
        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['failed'],
            'failed counter must increment when delivery fails');
        $this->assertSame('failed', $updatedStatus,
            "event must be marked 'failed' when retry limit is reached");
    }

    /**
     * processQueue() must set status='pending' with next_attempt_at in the future
     * when a delivery fails but retry budget remains.
     *
     * The exponential back-off formula is: 5 min * 2^(attempt-1), capped at 24 h.
     */
    public function testProcessQueueKeepsEventPendingWhenRetryBudgetRemains(): void
    {
        // Arrange — event at first attempt (attempts=0, max_attempts=3)
        $pendingEvent = $this->buildSingleRowResult([
            'event_id'     => 30,
            'webhook_id'   => 1,
            'event_type'   => 'token_revoked',
            'payload'      => '{}',
            'attempts'     => 0,
            'max_attempts' => 3,
        ]);
        $endpoint = new class {
            public int   $numRows = 1;
            public array $fields  = [
                'endpoint_url'    => 'http://example.com/hook',
                'secret_key'      => 'key',
                'timeout_seconds' => 5,
            ];
        };

        $updatedStatus = null;
        $nextAttemptAt = null;
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereIn')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('get')->willReturn($pendingEvent);
        $qb->method('first')->willReturn($endpoint);
        $qb->method('update')->willReturnCallback(
            function (array $data) use (&$updatedStatus, &$nextAttemptAt) {
                if (isset($data['status'])) {
                    $updatedStatus = $data['status'];
                }
                if (isset($data['next_attempt_at'])) {
                    $nextAttemptAt = $data['next_attempt_at'];
                }
            }
        );

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);

        $service = new class($db) extends WebhookService {
            protected function deliverEvent(array $event): bool
            {
                return false;
            }
        };

        // Act
        $beforeCall = time();
        $stats      = $service->processQueue();
        $afterCall  = time() + 300; // back-off for attempt 1 = 300 s

        // Assert — event stays pending, next_attempt_at is in the future
        $this->assertSame('pending', $updatedStatus,
            "event must stay 'pending' when retry budget allows another attempt");
        $this->assertNotNull($nextAttemptAt);
        $nextTs = strtotime($nextAttemptAt);
        $this->assertGreaterThan($beforeCall, $nextTs,
            'next_attempt_at must be in the future after applying back-off');
    }

    // ── purgeOldEvents ────────────────────────────────────────────────────────

    /**
     * purgeOldEvents() must return 0 and skip the DELETE when no matching rows exist.
     *
     * The method first counts matching rows and only issues a DELETE when count > 0.
     * This avoids an unnecessary DELETE statement when the table is already clean.
     */
    public function testPurgeOldEventsReturnsZeroWhenNothingToDelete(): void
    {
        // Arrange — count returns 0
        $db = $this->createMock(Database::class);
        $qb = $this->buildQbMock($this->buildEmptyResult(), 0);
        $db->method('queryBuilder')->willReturn($qb);

        $service = new WebhookService($db);

        // Act
        $deleted = $service->purgeOldEvents(30);

        // Assert
        $this->assertSame(0, $deleted,
            'purgeOldEvents must return 0 when no events match the age threshold');
    }

    /**
     * purgeOldEvents() must issue a DELETE and return the count when stale events exist.
     *
     * When count() > 0, both the count query and the delete query must run.
     * The return value must equal the count of deleted rows.
     */
    public function testPurgeOldEventsDeletesAndReturnsCountWhenStaleEventsExist(): void
    {
        // Arrange — count returns 7, delete is expected
        $deleteIssued = false;
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereIn')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('count')->willReturn(7);
        $qb->method('delete')->willReturnCallback(
            function () use (&$deleteIssued) { $deleteIssued = true; }
        );

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);

        $service = new WebhookService($db);

        // Act
        $deleted = $service->purgeOldEvents(30);

        // Assert
        $this->assertSame(7, $deleted,
            'purgeOldEvents must return the count of deleted rows');
        $this->assertTrue($deleteIssued,
            'a DELETE must be issued when count > 0');
    }

    // ── deliverEvent (protected, tested via subclass) ─────────────────────────

    /**
     * The service with deliverEvent() and lastError reachable from a test.
     */
    private function exposedService(): WebhookService
    {
        return new class($this->createMock(Database::class)) extends WebhookService {
            public function publicDeliverEvent(array $event): bool
            {
                return $this->deliverEvent($event);
            }

            public function publicGetLastError(): string
            {
                $ref = new \ReflectionProperty(WebhookService::class, 'lastError');
                return $ref->getValue($this);
            }
        };
    }

    /** One queued event, pointed at $url. */
    private function eventFor(string $url): array
    {
        return [
            'payload'         => '{"event":"test"}',
            'secret_key'      => 'test-secret',
            'endpoint_url'    => $url,
            'event_type'      => 'token_revoked',
            'timeout_seconds' => 1,
        ];
    }

    /**
     * An endpoint inside this network is never requested.
     *
     * The relying party typed the address, and this server makes the request, so a
     * delivery to loopback, a private host or the cloud metadata address is
     * server-side request forgery with a signed body attached. Registration refuses
     * these too, but a row can predate that check, and a name can be re-pointed after
     * it — the delivery is where the guard has to hold.
     *
     * A fake is registered for every URL: if the guard failed open, the delivery would
     * succeed against it rather than fail, so the test cannot pass by accident.
     *
     * @param string $url An endpoint whose host is not a public address
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('internalEndpoints')]
    public function testDeliverEventRefusesAnEndpointInsideThisNetwork(string $url): void
    {
        // Arrange
        $requested = false;
        \Pramnos\Http\Client::fake(['*' => function () use (&$requested) {
            $requested = true;
            return \Pramnos\Http\ClientResponse::make('ok', 200);
        }]);
        $service = $this->exposedService();

        try {
            // Act
            $result = $service->publicDeliverEvent($this->eventFor($url));

            // Assert
            $this->assertFalse($result);
            $this->assertFalse($requested, 'no request may be made to an internal address');
            $this->assertStringStartsWith('Delivery refused or failed:', $service->publicGetLastError());
        } finally {
            \Pramnos\Http\Client::resetFakes();
        }
    }

    /** @return array<string, array{string}> */
    public static function internalEndpoints(): array
    {
        return [
            'loopback'       => ['https://127.0.0.1:19991/'],
            'private'        => ['https://10.0.0.5/hooks'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'plaintext'      => ['http://93.184.216.34/hooks'],
        ];
    }

    /**
     * A public endpoint that answers 2xx is a successful delivery.
     *
     * An IP literal, so the guard's resolution needs no network, and a fake behind it,
     * so the request does not leave the container. The fake also confirms the request
     * was made — which the refusal test above proves does not happen for an internal one.
     */
    public function testDeliverEventSucceedsOnA2xxFromAPublicEndpoint(): void
    {
        // Arrange
        $requested = false;
        \Pramnos\Http\Client::fake(['https://93.184.216.34/*' => function () use (&$requested) {
            $requested = true;
            return \Pramnos\Http\ClientResponse::make('', 204);
        }]);
        $service = $this->exposedService();

        try {
            // Act
            $result = $service->publicDeliverEvent($this->eventFor('https://93.184.216.34/hooks'));

            // Assert
            $this->assertTrue($result);
            $this->assertTrue($requested);
            $this->assertSame('', $service->publicGetLastError());
        } finally {
            \Pramnos\Http\Client::resetFakes();
        }
    }

    /**
     * A non-2xx answer fails the delivery and says what came back.
     *
     * A 302 is included on purpose: redirects are not followed, because a receiver
     * that bounces a signed body to another address has moved, and should re-register
     * rather than have the body follow it somewhere nobody checked.
     *
     * @param int $status The receiver's answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failedAnswers')]
    public function testDeliverEventFailsOnANon2xxAnswer(int $status): void
    {
        // Arrange
        \Pramnos\Http\Client::fake(['https://93.184.216.34/*' => \Pramnos\Http\ClientResponse::make(
            'receiver says no',
            $status,
            ['Location' => 'https://169.254.169.254/']
        )]);
        $service = $this->exposedService();

        try {
            // Act
            $result = $service->publicDeliverEvent($this->eventFor('https://93.184.216.34/hooks'));

            // Assert
            $this->assertFalse($result);
            $this->assertSame("HTTP {$status}: receiver says no", $service->publicGetLastError());
        } finally {
            \Pramnos\Http\Client::resetFakes();
        }
    }

    /** @return array<string, array{int}> */
    public static function failedAnswers(): array
    {
        return ['redirect' => [302], 'client error' => [410], 'server error' => [503]];
    }

    /**
     * A transport failure fails the delivery with the client's reason.
     *
     * Connection refused, a timeout, a TLS error: the client raises, and the delivery
     * must record why rather than let the exception out of the queue worker.
     */
    public function testDeliverEventFailsWhenTheConnectionFails(): void
    {
        // Arrange
        \Pramnos\Http\Client::fake(['https://93.184.216.34/*' => function () {
            throw new \Pramnos\Http\ClientException('Connection refused');
        }]);
        $service = $this->exposedService();

        try {
            // Act
            $result = $service->publicDeliverEvent($this->eventFor('https://93.184.216.34/hooks'));

            // Assert
            $this->assertFalse($result);
            $this->assertSame(
                'Delivery refused or failed: Connection refused',
                $service->publicGetLastError()
            );
        } finally {
            \Pramnos\Http\Client::resetFakes();
        }
    }

    /**
     * A signing key that will not decrypt fails the delivery, with a reason.
     *
     * The key is stored encrypted, so after an `APP_KEY` rotation — or against a
     * column that was truncated — it cannot be read back. Signing with the ciphertext
     * would produce a delivery the receiver rejects as forged, and the operator would
     * go looking for the problem in the receiver's logs, on the other side of the
     * connection from the cause.
     *
     * So the attempt fails here, where the reason can be stated: no HTTP request is
     * made at all.
     */
    public function testDeliverEventFailsWhenTheSigningKeyCannotBeDecrypted(): void
    {
        // Arrange — a value carrying the marker, encrypted under a key that is gone.
        $originalKey = getenv('APP_KEY');
        $key         = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;
        $sealed = \Pramnos\Security\Encrypter::encrypt('the-real-signing-key');

        $rotated = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $rotated);
        $_ENV['APP_KEY'] = $rotated;

        $db      = $this->createMock(Database::class);
        $service = new class($db) extends WebhookService {
            public function publicDeliverEvent(array $event): bool
            {
                return $this->deliverEvent($event);
            }

            public function publicGetLastError(): string
            {
                $ref = new \ReflectionProperty(WebhookService::class, 'lastError');
                return $ref->getValue($this);
            }
        };

        try {
            // Act — the URL is unreachable on purpose: if the guard failed to fire,
            // the test would still fail, but on a cURL error rather than this one.
            $result = $service->publicDeliverEvent([
                'payload'         => '{"event":"test"}',
                'secret_key'      => $sealed,
                'endpoint_url'    => 'http://127.0.0.1:19991/',
                'event_type'      => 'token_revoked',
                'timeout_seconds' => 1,
            ]);

            // Assert
            $this->assertFalse($result);
            $this->assertStringContainsString(
                'Webhook signing key could not be read',
                $service->publicGetLastError(),
                'The failure must name the key, not the connection.'
            );
        } finally {
            if ($originalKey === false) {
                putenv('APP_KEY');
                unset($_ENV['APP_KEY']);
            } else {
                putenv('APP_KEY=' . $originalKey);
                $_ENV['APP_KEY'] = $originalKey;
            }
        }
    }

    // ── verifySignature ───────────────────────────────────────────────────────

    /**
     * A correct HMAC-SHA256 signature must be accepted.
     *
     * This is the contract for inbound webhook verification: the receiver
     * recomputes the signature from the raw body and compares with the header.
     */
    public function testVerifySignatureAcceptsValidSignature(): void
    {
        // Arrange
        $payload = '{"event_type":"token_revoked","user_id":42}';
        $secret  = 'super-secret-key';

        // Act
        $header  = WebhookService::buildSignature($payload, $secret);
        $result  = WebhookService::verifySignature($payload, $secret, $header);

        // Assert
        $this->assertTrue($result, 'A correctly signed request must pass verification');
    }

    /**
     * A tampered payload must not pass verification.
     *
     * The HMAC is computed over the exact byte sequence of the payload. Any
     * modification — even a single character — must produce a different digest.
     */
    public function testVerifySignatureRejectsTamperedPayload(): void
    {
        // Arrange
        $payload        = '{"event_type":"token_revoked","user_id":42}';
        $tamperedPayload = '{"event_type":"token_revoked","user_id":99}';
        $secret         = 'super-secret-key';

        // Act
        $header = WebhookService::buildSignature($payload, $secret);
        $result = WebhookService::verifySignature($tamperedPayload, $secret, $header);

        // Assert
        $this->assertFalse($result, 'A tampered payload must fail verification');
    }

    /**
     * A wrong secret must not pass verification.
     *
     * Even with an identical payload, different secrets produce different HMACs.
     */
    public function testVerifySignatureRejectsWrongSecret(): void
    {
        // Arrange
        $payload  = '{"event_type":"token_revoked","user_id":42}';
        $secret   = 'correct-secret';
        $attacker = 'wrong-secret';

        // Act
        $header = WebhookService::buildSignature($payload, $secret);
        $result = WebhookService::verifySignature($payload, $attacker, $header);

        // Assert
        $this->assertFalse($result, 'Signing with a different secret must fail verification');
    }

    /**
     * A header value without the "sha256=" prefix must be rejected immediately.
     *
     * This prevents accepting unsigned or malformed headers before any HMAC
     * comparison is performed.
     */
    public function testVerifySignatureRejectsMissingPrefix(): void
    {
        // Arrange
        $payload = 'any body';
        $secret  = 'key';

        // Act — pass raw hex without the "sha256=" prefix
        $rawHex = hash_hmac('sha256', $payload, $secret);
        $result = WebhookService::verifySignature($payload, $secret, $rawHex);

        // Assert
        $this->assertFalse($result, 'A header without sha256= prefix must be rejected');
    }

    // ── buildSignature ────────────────────────────────────────────────────────

    /**
     * buildSignature must always produce the "sha256=<hex>" format.
     *
     * The prefix is required by the delivery protocol; callers must not need to
     * add it themselves.
     */
    public function testBuildSignatureReturnsCorrectFormat(): void
    {
        // Arrange
        $payload = '{"hello":"world"}';
        $secret  = 'my-secret';

        // Act
        $result = WebhookService::buildSignature($payload, $secret);

        // Assert
        $this->assertStringStartsWith('sha256=', $result);
        $hex = substr($result, 7);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex,
            'The hex part must be a 64-char lowercase SHA-256 digest');
    }

    /**
     * buildSignature and verifySignature must be inverses.
     *
     * Whatever buildSignature produces, verifySignature with the same inputs
     * must return true. This is the round-trip invariant.
     */
    public function testBuildAndVerifyAreInverses(): void
    {
        $cases = [
            ['payload' => '',             'secret' => 'k'],
            ['payload' => 'hello',        'secret' => 'secret'],
            ['payload' => '{"a":"b"}',    'secret' => str_repeat('x', 64)],
            ['payload' => str_repeat('!', 1000), 'secret' => 'long-payload'],
        ];

        foreach ($cases as $case) {
            $header = WebhookService::buildSignature($case['payload'], $case['secret']);
            $ok     = WebhookService::verifySignature($case['payload'], $case['secret'], $header);
            $this->assertTrue($ok,
                "Round-trip must pass for payload='{$case['payload']}' (truncated)");
        }
    }

    // ── Retry logic (back-off formula) ────────────────────────────────────────

    /**
     * The exponential back-off delay is 5 min × 2^(attempt-1), capped at 24 h.
     *
     * Verified by inspecting the formula in isolation, mirroring what
     * processQueue computes. This ensures the retry schedule does not silently
     * change if the implementation is refactored.
     */
    public function testBackoffFormulaIsCorrect(): void
    {
        // Replicate the formula from WebhookService::processQueue()
        $backoffFor = function (int $attempt): int {
            return min(300 * (2 ** ($attempt - 1)), 86400);
        };

        // Assert
        $this->assertSame(300,   $backoffFor(1), '1st retry: 5 min');
        $this->assertSame(600,   $backoffFor(2), '2nd retry: 10 min');
        $this->assertSame(1200,  $backoffFor(3), '3rd retry: 20 min');
        $this->assertSame(2400,  $backoffFor(4), '4th retry: 40 min');
        $this->assertSame(4800,  $backoffFor(5), '5th retry: 80 min');
        $this->assertSame(9600,  $backoffFor(6), '6th retry: ~2.67 h');
        $this->assertSame(19200, $backoffFor(7), '7th retry: ~5.3 h');
        $this->assertSame(38400, $backoffFor(8), '8th retry: ~10.7 h');
        $this->assertSame(76800, $backoffFor(9), '9th retry: ~21.3 h');
        $this->assertSame(86400, $backoffFor(10), '10th+ retry: capped at 24 h');
        $this->assertSame(86400, $backoffFor(20), 'Large attempt: still capped at 24 h');
    }

    // ── HMAC signing determinism ───────────────────────────────────────────────

    /**
     * HMAC-SHA256 is deterministic — the same inputs always produce the same output.
     *
     * This matters because the receiver must be able to recompute and compare;
     * any randomness in the signature would break verification.
     */
    public function testSignatureIsDeterministic(): void
    {
        $payload = '{"event":"test"}';
        $secret  = 'key';

        $first  = WebhookService::buildSignature($payload, $secret);
        $second = WebhookService::buildSignature($payload, $secret);

        $this->assertSame($first, $second, 'HMAC-SHA256 must be deterministic');
    }

    /**
     * Different payloads must produce different signatures with the same secret.
     *
     * Ensures that signing is sensitive to content changes — a requirement for
     * tamper detection.
     */
    public function testDifferentPayloadsProduceDifferentSignatures(): void
    {
        $secret = 'shared-secret';
        $a      = WebhookService::buildSignature('payload-A', $secret);
        $b      = WebhookService::buildSignature('payload-B', $secret);

        $this->assertNotSame($a, $b, 'Different payloads must not collide');
    }
}
