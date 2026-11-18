<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

test('database seeding creates one verified administrator who can log in', function () {
    $this->seed();

    $user = User::query()->where('email', 'testingadmin@gmail.com')->firstOrFail();
    $this->assertDatabaseCount('users', 1);
    expect($user->is_admin)->toBeTrue();
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect(Hash::check('testingadmin1234', $user->password))->toBeTrue();

    $this->post(route('login.store'), [
        'email' => 'testingadmin@gmail.com',
        'password' => 'testingadmin1234',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('admin seeding promotes an existing account without duplicating users', function () {
    $user = User::factory()->unverified()->create(['email' => 'testingadmin@gmail.com']);

    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    $this->assertDatabaseCount('users', 1);
    expect($user->fresh()->is_admin)->toBeTrue();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
