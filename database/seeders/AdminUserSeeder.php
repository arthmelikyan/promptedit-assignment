<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrNew(['email' => 'testingadmin@gmail.com']);

        $user->forceFill([
            'name' => 'Testing Admin',
            'password' => 'testingadmin1234',
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();
    }
}
