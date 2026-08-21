<?php

namespace Database\Seeders;

use App\Models\StatementMovement;
use Illuminate\Database\Seeder;

class StatementMovementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StatementMovement::factory()->create();
    }
}
