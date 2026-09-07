<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Passkey\PasskeyService;

/**
 * Asking twice whether a user has a passkey costs one query.
 *
 * Three callers in the framework alone — `FactorEnrolment` twice, `LoginFlow` once — and
 * each is right to ask; **none can know another already did.** So one page load ran the
 * same statement three times:
 *
 * ```
 * 0.29ms  SELECT credential_id FROM passkey_credentials WHERE userid = 2 AND is_active
 * 0.13ms  SELECT credential_id FROM passkey_credentials WHERE userid = 2 AND is_active
 * 0.13ms  SELECT credential_id FROM passkey_credentials WHERE userid = 2 AND is_active
 * ```
 *
 * **None of them is slow, which is exactly why it survived.** A tenth of a millisecond
 * appears in no profile, and neither do three of them. What made it visible was the debug
 * toolbar listing the queries, where *the same row, three times* reads as a mistake in a way
 * that 0.55 ms of total time never will.
 */
#[CoversClass(PasskeyService::class)]
class PasskeyCredentialMemoTest extends TestCase
{
    /**
     * A service that counts its queries instead of running them.
     *
     * `activeCredentialIds()` is the seam: it is `protected`, so the counting happens at the
     * boundary the memoisation is meant to protect rather than inside the query builder.
     */
    private function service(array $idsByUser): object
    {
        return new class ($idsByUser) extends PasskeyService {
            public int $queries = 0;

            /** @param array<int, list<string>> $idsByUser */
            public function __construct(private array $idsByUser)
            {
                // The real constructor wants a database and a relying party.
            }

            protected function activeCredentialIds(int $userId): array
            {
                if (array_key_exists($userId, $this->memo)) {
                    return $this->memo[$userId];
                }

                $this->queries++;

                return $this->memo[$userId] = $this->idsByUser[$userId] ?? [];
            }

            /** @var array<int, list<string>> */
            private array $memo = [];

            public function forget(int $userId): void
            {
                unset($this->memo[$userId]);
            }
        };
    }

    /**
     * `hasCredentials()` twice is one query, and an **empty** list is still an answer.
     *
     * The case a truthiness test would get wrong: a user with no passkeys is the common one,
     * and `[] ?: query()` would re-ask for exactly those users on every call.
     */
    public function testAnEmptyAnswerIsRemembered(): void
    {
        // Arrange — a user with no passkeys
        $service = $this->service([2 => []]);

        // Act
        $first  = $service->hasCredentials(2);
        $second = $service->hasCredentials(2);

        // Assert
        $this->assertFalse($first);
        $this->assertFalse($second);
        $this->assertSame(1, $service->queries, 'an empty list was re-queried');
    }

    /**
     * A user with passkeys is remembered too, and a different user is not confused with them.
     *
     * Keyed by user id, which matters on a screen listing several accounts: one shared answer
     * would report everybody's passkeys as the first user's.
     */
    public function testTheAnswerIsPerUser(): void
    {
        // Arrange
        $service = $this->service([2 => ['abc'], 3 => []]);

        // Act
        $service->hasCredentials(2);
        $service->hasCredentials(2);
        $service->hasCredentials(3);

        // Assert
        $this->assertTrue($service->hasCredentials(2));
        $this->assertFalse($service->hasCredentials(3));
        $this->assertSame(2, $service->queries, 'one query per user, and no more');
    }

    /**
     * Revoking or adding a passkey forgets the answer for that user only.
     *
     * The half that makes memoisation safe rather than merely fast: a cached answer that
     * outlives the thing it describes is worse than a query. And keyed, so revoking one user's
     * passkey does not make the rest of the request re-ask about everybody.
     */
    public function testChangingAUsersPasskeysForgetsOnlyThatUser(): void
    {
        // Arrange
        $service = $this->service([2 => ['abc'], 3 => ['def']]);
        $service->hasCredentials(2);
        $service->hasCredentials(3);

        $this->assertSame(2, $service->queries);

        // Act
        $service->forget(2);
        $service->hasCredentials(2);
        $service->hasCredentials(3);

        // Assert — user 2 re-asked, user 3 not
        $this->assertSame(3, $service->queries);
    }

    /**
     * The production class clears its memo where the answer changes.
     *
     * Read from the source rather than executed: `revokeCredential()` and
     * `persistCredential()` both need a database, and what is worth pinning is that neither
     * leaves a stale answer behind. Without this, deleting either call leaves every test above
     * green — the memo would be correct, complete, and never invalidated.
     */
    public function testBothMutatorsForgetTheMemo(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            (new \ReflectionClass(PasskeyService::class))->getFileName()
        );

        foreach (array('revokeCredential', 'persistCredential') as $method) {
            $body = substr($source, (int) strpos($source, 'function ' . $method));
            $body = substr($body, 0, (int) strpos($body, "\n    }\n"));

            // Assert
            $this->assertStringContainsString(
                'forgetCredentialIds(',
                $body,
                $method . '() changes a user\'s passkeys and does not forget the memoised answer'
            );
        }
    }
}
