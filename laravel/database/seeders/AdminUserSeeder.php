<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'ivan30yanuar@gmail.com');
        $name  = env('ADMIN_NAME', 'Ivan');
        $pass  = env('ADMIN_PASSWORD', 'Ivan2026');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'     => $name,
                'password' => Hash::make($pass),
            ]
        );

        $user->assignRole('admin');

        $this->command->info("Admin user: {$email}");
        $this->command->warn("Password: {$pass} — ganti segera setelah login pertama!");
    }
}
