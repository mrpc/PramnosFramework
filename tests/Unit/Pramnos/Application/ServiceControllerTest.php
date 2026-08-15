<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\ServiceController;

/**
 * Models are usable outside an MVC request.
 *
 * `Model::__construct()` requires a `Controller`, which reads like a hard dependency
 * on the MVC stack. It is not: of the five references to `$this->controller` inside
 * `Model`, two are real uses and three exist only to pass the same controller on to
 * the next model. `Orm\Relations\Relation` documented the dependency with a reason
 * that was false — *"so the model can reach the database"* — when `Model` calls
 * `Database::getInstance()` itself.
 *
 * The cost of the dependency was measured before this class was written, because
 * "it is expensive" was the assumption worth checking: `new Controller()` is
 * **1.54 µs**, and the `Application::getInstance()` behind it is 1.3 ms cold and
 * 0.002 ms warm. It costs nothing and looks like it costs a great deal, which is
 * exactly the kind of thing that needs a name rather than a workaround.
 */
class ServiceControllerTest extends TestCase
{
    /**
     * Drops the shared instance so tests cannot inherit each other's.
     *
     * @return void
     */
    protected function setUp(): void
    {
        ServiceController::forget();
    }

    /**
     * Leaves nothing behind for whatever runs next.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ServiceController::forget();
    }

    /**
     * It is a `Controller`, which is the whole contract `Model` asks for.
     *
     * @return void
     */
    public function testItSatisfiesTheModelConstructor(): void
    {
        // Act
        $model = new class (ServiceController::shared()) extends Model {
            /** @var string */
            protected $_dbtable = 'anything';
        };

        // Assert
        $this->assertInstanceOf(Model::class, $model);
        $this->assertInstanceOf(Controller::class, $model->controller);
    }

    /**
     * `shared()` hands back the same instance every time.
     *
     * Models built from one controller can resolve each other through `getModel()`;
     * a fresh controller per model also re-runs a reflection and a permissions
     * normalisation for nothing.
     *
     * @return void
     */
    public function testSharedReturnsOneInstance(): void
    {
        // Act & Assert
        $this->assertSame(ServiceController::shared(), ServiceController::shared());
    }

    /**
     * `forget()` really drops it.
     *
     * Without this a test that swaps applications inherits the previous one through
     * the controller — a leak that surfaces as a failure in whichever class happens
     * to run next, which is the hardest kind to trace back.
     *
     * @return void
     */
    public function testForgetDropsTheSharedInstance(): void
    {
        // Arrange
        $first = ServiceController::shared();

        // Act
        ServiceController::forget();

        // Assert
        $this->assertNotSame($first, ServiceController::shared());
    }

    /**
     * It grants no permissions.
     *
     * The guard that matters most here. Code outside a request has no user, and a
     * controller that quietly behaved as though it did would be a far worse thing to
     * put in the framework than the inconvenience it removes. If this ever starts
     * failing, something has given the non-request path an identity.
     *
     * @return void
     */
    public function testItGrantsNoPermissions(): void
    {
        // Act
        $permissions = (new \ReflectionProperty(Controller::class, 'user_permissions'))
            ->getValue(ServiceController::shared());

        // Assert
        $this->assertSame([], $permissions);
    }

    /**
     * The ORM's relation comment no longer states a false reason.
     *
     * It said the controller is passed *"so the model can reach the database"*. It is
     * not, and a wrong reason in a comment is worse than none: it makes the
     * dependency look load-bearing, so anybody trying to use models outside a request
     * reads it and concludes they need a request.
     *
     * @return void
     */
    public function testTheRelationCommentDoesNotClaimDatabaseAccess(): void
    {
        // Arrange
        $file = dirname(__DIR__, 4)
            . '/src/Pramnos/Application/Orm/Relations/Relation.php';

        // Act
        $source = (string) file_get_contents($file);

        // Assert
        $this->assertNotSame('', $source, 'Relation.php was not read.');
        $this->assertStringNotContainsString(
            'so the model can reach the database',
            $source
        );
    }
}
