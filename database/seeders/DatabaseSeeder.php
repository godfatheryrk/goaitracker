<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // E2E auth user: auth.setup.ts logs in as this account to produce storageState.
        User::factory()->create([
            'name' => 'Rafal E2E',
            'email' => 'rafal@test.local',
            'password' => Hash::make('password123'),
        ]);
    }
}
