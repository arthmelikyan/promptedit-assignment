<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('app.admin_password');

        if (blank($password) || (app()->isProduction() && $password === 'testingadmin1234')) {
            throw new RuntimeException('Set a non-empty ADMIN_PASSWORD and use a unique password in production.');
        }

        $user = User::query()->firstOrNew(['email' => 'testingadmin@gmail.com']);

        $user->forceFill([
            'name' => 'Testing Admin',
            'password' => $password,
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();
    }
}
