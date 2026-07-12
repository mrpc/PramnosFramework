<?php
namespace TestApp\Seeders;

use Pramnos\Database\Seeder;

/**
 * Seed #PREFIX#zzzseeder82e976c6e6c1s with fake data.
 * Auto-generated: 19/05/2026 22:37
 */
class ZzzSeeder82E976C6E6C1Seeder extends Seeder
{
    protected string $table = '#PREFIX#zzzseeder82e976c6e6c1s';

    public function run(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->insert($this->table, [
                // TODO: add column => fake-value pairs
            ]);
        }
    }
}
