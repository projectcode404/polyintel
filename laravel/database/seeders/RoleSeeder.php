<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Buat roles
        $admin   = Role::firstOrCreate(['name' => 'admin',   'guard_name' => 'web']);
        $analyst = Role::firstOrCreate(['name' => 'analyst', 'guard_name' => 'web']);

        $this->command->info('Roles created: admin, analyst');
    }
}
