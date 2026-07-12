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
     * Seed the single admin account from config/control_panel.php (env-driven).
     * Idempotent so re-running never creates duplicates.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => config('control_panel.admin.email')],
            [
                'name' => config('control_panel.admin.name'),
                'password' => Hash::make(config('control_panel.admin.password')),
                'email_verified_at' => now(),
            ],
        );
    }
}
