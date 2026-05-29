<?php

namespace Pramnos\Database;

/**
 * Base class for database seeders.
 *
 * A seeder populates a table with deterministic or factory-generated data for
 * development and testing. Subclasses implement run() and use either
 * $this->insert() for one-off rows or $this->factory() to spin up a Factory.
 *
 * Example — seeder that mixes factory-generated rows and a fixed fixture:
 *
 *   class UserSeeder extends Seeder
 *   {
 *       public function run(): void
 *       {
 *           // 20 random Greek users
 *           $this->factory(UserFactory::class)->count(20)->create();
 *
 *           // Fixed admin account
 *           $this->factory(UserFactory::class)
 *               ->state(['role' => 'admin', 'email' => 'admin@example.com'])
 *               ->create();
 *
 *           // Direct insert for data that has no factory
 *           $this->insert('settings', ['key' => 'site_name', 'value' => 'Demo']);
 *       }
 *   }
 *
 * Calling other seeders:
 *
 *   $this->call(PostSeeder::class);
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
abstract class Seeder
{
    abstract public function run(): void;

    /**
     * Create a Factory instance for the given class, pre-bound to the same
     * Database connection used by this seeder.
     *
     * @template T of Factory
     * @param class-string<T> $factoryClass  Fully-qualified Factory subclass name.
     * @param string|null     $locale        Faker locale (null = factory default).
     * @return T
     * @throws \InvalidArgumentException When $factoryClass is not a Factory subclass.
     */
    public function factory(string $factoryClass, ?string $locale = null): Factory
    {
        if (!is_subclass_of($factoryClass, Factory::class)) {
            throw new \InvalidArgumentException(
                "{$factoryClass} must extend " . Factory::class
            );
        }
        return new $factoryClass($locale);
    }

    /**
     * Insert a single row into a table via the active Database connection.
     *
     * @param string               $table Table name (may include #PREFIX# placeholder)
     * @param array<string, mixed> $data  Column → value map
     */
    protected function insert(string $table, array $data): void
    {
        Database::getInstance()->insertDataToTable($table, $data); // @codeCoverageIgnore
    }

    /**
     * Run another seeder from within this seeder.
     * Useful for composing seeders without repeating setup logic.
     *
     * @param class-string<Seeder> $seederClass  Fully-qualified Seeder subclass name.
     * @throws \InvalidArgumentException When $seederClass is not a Seeder subclass.
     */
    public function call(string $seederClass): void
    {
        if (!is_subclass_of($seederClass, self::class)) {
            throw new \InvalidArgumentException(
                "{$seederClass} must extend " . self::class
            );
        }
        (new $seederClass())->run();
    }
}
