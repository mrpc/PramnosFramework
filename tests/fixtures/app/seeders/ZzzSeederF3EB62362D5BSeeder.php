<?php
namespace TestApp\Seeders;

use Pramnos\Database\Seeder;

/**
 * Seed #PREFIX#zzzseederf3eb62362d5bs with fake data.
 * Auto-generated: 19/05/2026 22:40
 */
class ZzzSeederF3EB62362D5BSeeder extends Seeder
{
    protected string $table = '#PREFIX#zzzseederf3eb62362d5bs';

    public function run(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->insert($this->table, [
                // TODO: add column => fake-value pairs
            ]);
        }
    }
}
