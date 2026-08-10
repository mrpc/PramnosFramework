<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Permissions;

/**
 * Exposes the store selection and the authserver mapping without a database.
 */
class PermissionsStoreProbe extends Permissions
{
    /** @var string legacy|authserver|none */
    public string $store = 'none';

    /** @var array<int, array<string, mixed>> Grants the resolver "returns". */
    public array $grants = [];

    public function __construct()
    {
        // No parent constructor: this exercises the decision logic, which needs
        // no connection.
    }

    protected function activeStore()
    {
        return $this->store;
    }

    protected function _isAllowedFromAuthserver($subject, $resource, $privilege,
        $resourceElement = '', $subjectType = 'user')
    {
        // Reuse the production mapping over injected grants by calling the
        // parent with a stubbed resolver result is not possible without a
        // database, so the mapping itself is exercised through matchGrants().
        return $this->matchGrants($resource, $privilege, $resourceElement);
    }

    /**
     * The same matching rules the production method applies, over $grants.
     *
     * @return bool|null
     */
    private function matchGrants(string $resource, string $privilege, string $element): ?bool
    {
        $verdict = null;
        foreach ($this->grants as $grant) {
            if (($grant['object_type'] ?? '') !== $resource) {
                continue;
            }
            $objectId = $grant['object_id'] ?? null;
            if ($element !== '' && $objectId !== null && $objectId !== $element) {
                continue;
            }
            if ($element === '' && $objectId !== null) {
                continue;
            }
            $action = (string) ($grant['action'] ?? '');
            if ($action !== $privilege && $action !== '*') {
                continue;
            }
            if (($grant['conditions'] ?? null) !== null) {
                continue;
            }
            if (($grant['grant'] ?? '') === 'deny') {
                return false;
            }
            $verdict = true;
        }

        return $verdict;
    }
}

/**
 * Covers where `Pramnos\Auth\Permissions` reads from.
 *
 * The class historically read `<prefix>permissions`, a table no migration
 * creates — so on a stock installation the lookup always failed, and a failed
 * lookup was reported as `false`, indistinguishable from a deny. Callers that
 * trusted it refused everything. It now answers from whichever store the
 * installation has, and says so when it has none.
 */
class PermissionsStoreSelectionTest extends TestCase
{
    /**
     * With no store at all, the tri-state call says "no opinion".
     *
     * This is the distinction that was missing. `false` here means "denied",
     * and a caller cannot tell that apart from a store that was never
     * provisioned — which is how a stock installation came to refuse every
     * action to every user.
     */
    public function testNoStoreIsNoOpinionNotDenial(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store = 'none';

        // Act + Assert
        $this->assertNull(
            $permissions->isAllowed(2, 'customer', 'view', '', 'module', 'user', false)
        );
    }

    /**
     * The documented contract for the default flag is unchanged: an unknown
     * permission is "no". Existing callers that never asked for the tri-state
     * see exactly what they saw before.
     */
    public function testNoStoreStillAnswersFalseForTheNonTriStateCall(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store = 'none';

        // Act + Assert
        $this->assertFalse(
            $permissions->isAllowed(2, 'customer', 'view', '', 'module', 'user', true)
        );
    }

    /**
     * An allow grant in the new schema answers true through the old API.
     */
    public function testAnAllowGrantFromTheNewSchemaIsHonoured(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store  = 'authserver';
        $permissions->grants = [
            ['object_type' => 'customer', 'object_id' => null, 'action' => 'view', 'grant' => 'allow'],
        ];

        // Act + Assert
        $this->assertTrue(
            $permissions->isAllowed(2, 'customer', 'view', '', 'module', 'user', false)
        );
    }

    /**
     * A deny grant wins, as it does everywhere else in the new system.
     */
    public function testADenyGrantFromTheNewSchemaIsHonoured(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store  = 'authserver';
        $permissions->grants = [
            ['object_type' => 'customer', 'object_id' => null, 'action' => 'view', 'grant' => 'allow'],
            ['object_type' => 'customer', 'object_id' => null, 'action' => 'delete', 'grant' => 'deny'],
        ];

        // Act + Assert
        $this->assertFalse(
            $permissions->isAllowed(2, 'customer', 'delete', '', 'module', 'user', false)
        );
    }

    /**
     * An action nobody granted is no opinion, not a denial — the same tri-state
     * the legacy table gave for a missing row.
     */
    public function testAnUngrantedActionIsNoOpinion(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store  = 'authserver';
        $permissions->grants = [
            ['object_type' => 'customer', 'object_id' => null, 'action' => 'view', 'grant' => 'allow'],
        ];

        // Act + Assert
        $this->assertNull(
            $permissions->isAllowed(2, 'customer', 'edit', '', 'module', 'user', false)
        );
    }

    /**
     * The `*` action means "everything on this object", which is how the new
     * schema expresses what the legacy API called the `admin` privilege.
     */
    public function testTheWildcardActionCoversAnyPrivilege(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store  = 'authserver';
        $permissions->grants = [
            ['object_type' => 'customer', 'object_id' => null, 'action' => '*', 'grant' => 'allow'],
        ];

        // Act + Assert
        $this->assertTrue(
            $permissions->isAllowed(2, 'customer', 'anything', '', 'module', 'user', false)
        );
    }

    /**
     * A grant carrying ABAC conditions is not treated as an unconditional one.
     *
     * The resolver passes conditions through for the application to evaluate
     * against its own request context. This API cannot express or receive one,
     * so honouring the grant regardless would hand out access the rule did not
     * give.
     */
    public function testAConditionalGrantIsNotHonouredHere(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store  = 'authserver';
        $permissions->grants = [
            [
                'object_type' => 'customer',
                'object_id'   => null,
                'action'      => 'view',
                'grant'       => 'allow',
                'conditions'  => [['field' => 'ownerid', 'op' => '=', 'value' => 'self']],
            ],
        ];

        // Act + Assert
        $this->assertNull(
            $permissions->isAllowed(2, 'customer', 'view', '', 'module', 'user', false)
        );
    }

    /**
     * A grant scoped to one object does not answer for another, and a grant on
     * "all objects of this type" does not answer a question about one specific
     * object either — the caller asked something narrower than the rule states.
     */
    public function testObjectScopingIsRespected(): void
    {
        // Arrange
        $permissions = new PermissionsStoreProbe();
        $permissions->store  = 'authserver';
        $permissions->grants = [
            ['object_type' => 'customer', 'object_id' => '42', 'action' => 'view', 'grant' => 'allow'],
        ];

        // Act + Assert
        $this->assertTrue(
            $permissions->isAllowed(2, 'customer', 'view', '42', 'module', 'user', false),
            'the object it was granted on'
        );
        $this->assertNull(
            $permissions->isAllowed(2, 'customer', 'view', '43', 'module', 'user', false),
            'a different object'
        );
    }
}
