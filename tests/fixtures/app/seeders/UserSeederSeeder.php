<?php
namespace App\Seeders;

use Pramnos\Database\Seeder;

/**
 * Seed users with fake data.
 * Auto-generated: 02/06/2026 22:48
 */
class UserSeederSeeder extends Seeder
{
    protected string $table = 'users';

    public function run(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->insert($this->table, [
                // TODO: add column => fake-value pairs
            ]);
        }
    }
}
