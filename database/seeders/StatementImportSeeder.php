<?php

namespace Database\Seeders;

use App\Models\StatementImport;
use Illuminate\Database\Seeder;

class StatementImportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StatementImport::factory()->create();
    }
}
