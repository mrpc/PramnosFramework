<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * What `create:crud` is allowed to emit.
 *
 * WHAT: three things the generated model and API controller must never contain, and the
 *       timestamp settings the generated model must carry.
 * WHY:  generated code is read as an example. Every project that runs `create:crud` gets
 *       the same lines, and each of these was wrong in a way nothing failed on — a scaffold
 *       that is multi-tenant by default (because `init` enables `authserver`) shipped a
 *       create action that took the tenant from the request.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedCrudSafetyTest extends TestCase
{
    private function stub(string $name): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/' . $name
        );
    }

    private function generator(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/MakeCommandBase.php'
        );
    }

    /**
     * The generated controller does not read `$_SESSION['user']`.
     *
     * It opened `createX()` with `$user = $_SESSION['user'];` and never used it. Unused is
     * the smaller half: the key is absent on every bearer-token request, so a project with
     * warnings turned up got one on every create — from a line that did nothing.
     */
    public function testTheGeneratedControllerDoesNotReadTheSessionUser(): void
    {
        // Act & Assert
        $this->assertStringNotContainsString('$_SESSION', $this->stub('api-controller.stub'));
    }

    /**
     * The tenant column is never assigned from the request.
     *
     * `$model->organization_id = Request::staticGet('organization_id', …)` is a
     * cross-tenant write with a straight face: whatever the caller sends is what the row is
     * filed under, so anyone authenticated can create a record inside somebody else's
     * organisation. It comes from who is asking.
     */
    public function testTheTenantColumnIsNotTakenFromTheRequest(): void
    {
        // Arrange — the names the generator treats as the tenant.
        $this->assertTrue(MakeCommandBase::isTenantColumn('organization_id'));
        $this->assertTrue(MakeCommandBase::isTenantColumn('ORGANIZATION_ID'));
        $this->assertFalse(MakeCommandBase::isTenantColumn('organization_name'));

        // Act & Assert — the generator emits the seam, not a request read.
        $source = $this->generator();
        $this->assertStringContainsString('$this->currentOrganizationId()', $source);
        $this->assertTrue(
            method_exists(\Pramnos\Application\ApiCrudController::class, 'currentOrganizationId'),
            'the generated line calls a method the base controller must provide'
        );
    }

    /**
     * Nothing possibly-null reaches `trim()` or `strip_tags()` uncast.
     *
     * A nullable column's default is `null`, and both functions have been deprecated for a
     * null argument since PHP 8.1 — an error under a strict handler, on the first create
     * that leaves an optional field empty. Asserted on the emitting code, because the
     * generated file only exists in somebody else's project.
     */
    public function testNothingPossiblyNullIsTrimmedOrStripped(): void
    {
        // Act — the emitted expressions, as string literals in the generator.
        $source = $this->generator();

        // Assert — no `trim(`/`strip_tags(` applied straight to a staticGet call.
        $patterns = [
            'trim(strip_tags(\\Pramnos\\Http\\Request::staticGet(',
            'trim(\\Pramnos\\Http\\Request::staticGet(',
            "trim(strip_tags(\\\$request->get(",
        ];
        foreach ($patterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $source,
                'a nullable column would pass null into trim()/strip_tags()'
            );
        }
    }

    /**
     * Every generated `load()` call uses the return value.
     *
     * WHAT: no stub contains `$model->load(` as a bare statement; the shape is
     *       `$model = (new Model($this))->load($id);`.
     *
     * WHY:  a model that scopes itself to a tenant refuses a row belonging to somebody else
     *       by returning a **different instance** — and `_load()` has already written every
     *       column onto `$this` by the time the subclass looks. So the generated shape
     *
     *       ```php
     *       $model = new Thing($this);
     *       $model->load((int) $id);           // return value discarded
     *       if ($model->thing_id == 0) { … }   // populated, so this passes
     *       ```
     *
     *       inspects the object that was refused and serves another organisation's row. One
     *       project had it in nine call sites, two of them MCP tools an outside assistant
     *       can reach, and the model's own isolation test passed throughout — because that
     *       test uses the return value, which is correct.
     *
     *       Asserted on the stubs rather than on a generated file: this is about the shape
     *       every project gets by default, and the default is the whole defect.
     *
     * @return void
     */
    public function testEveryGeneratedLoadUsesTheReturnValue(): void
    {
        // Arrange
        $stubs = ['api-controller.stub', 'crud-controller.stub', 'crud-model-test.stub'];
        $offenders = [];

        // Act
        foreach ($stubs as $stub) {
            $source = $this->stub($stub);
            $this->assertNotSame('', $source, $stub . ' is empty, so this checks nothing');

            foreach (explode("\n", $source) as $number => $line) {
                // A `load(` that is the whole statement: no `=` before it on the line, so
                // whatever it returns goes nowhere.
                if (!preg_match('/^\s*\$\w+->load\(/', $line)) {
                    continue;
                }
                $offenders[] = $stub . ':' . ($number + 1) . ' — ' . trim($line);
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "These discard what load() returns, so a tenant-scoped model's refusal is\n"
            . "ignored and the row it refused is served:\n" . implode("\n", $offenders)
        );
    }

    /**
     * The generated model is an `OrmModel`.
     *
     * It extended the legacy `\Pramnos\Application\Model`, on which a global scope is
     * accepted and silently never applied — so tenant isolation added later looks like
     * working code and leaks on every list. `OrmModel` extends that same class, so nothing
     * a generated model could already do stops working.
     */
    public function testTheGeneratedModelIsAnOrmModel(): void
    {
        // Act & Assert
        $this->assertStringContainsString(
            'extends \\Pramnos\\Application\\OrmModel',
            $this->stub('crud-model.stub')
        );
        $this->assertTrue(
            is_subclass_of(\Pramnos\Application\OrmModel::class, \Pramnos\Application\Model::class),
            'the switch is only safe because OrmModel is a Model'
        );
    }

    /**
     * A table without the timestamp columns gets them turned off.
     *
     * `OrmModel` stamps `created_at` / `updated_at` on every save by assigning the
     * property — on a table that has neither, that is a dynamic property (deprecated since
     * PHP 8.2) and then an INSERT naming a column that does not exist. The generator knows
     * the column list, so it settles this at generation time rather than at the first save.
     */
    public function testTimestampsAreDisabledForATableWithoutThem(): void
    {
        // Act & Assert
        $this->assertSame('', MakeCommandBase::ormOptionsFor(['id', 'created_at', 'updated_at']));

        $none = MakeCommandBase::ormOptionsFor(['id', 'name']);
        $this->assertStringContainsString('$timestamps = false', $none);

        // Only one of the two: the missing column is disabled by name, so the other keeps
        // working. Emptying the name is what `touchTimestamps()` checks.
        $partial = MakeCommandBase::ormOptionsFor(['id', 'created_at']);
        $this->assertStringContainsString("\$updatedAtColumn = ''", $partial);
        $this->assertStringNotContainsString('createdAtColumn', $partial);
    }
}
