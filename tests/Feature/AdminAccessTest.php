<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('admin gates allow only administrators', function (?bool $isAdmin, bool $allowed) {
    $user = match ($isAdmin) {
        true => User::factory()->admin()->make(),
        false => User::factory()->make(),
        null => null,
    };

    expect(Gate::forUser($user)->allows('admin'))->toBe($allowed);
    expect(Gate::forUser($user)->allows('viewHorizon'))->toBe($allowed);
})->with([
    'administrator' => [true, true],
    'regular user' => [false, false],
    'guest' => [null, false],
]);

test('guests are redirected to login from horizon', function () {
    $this->get(route('horizon.index'))->assertRedirect(route('login'));
});

test('regular users cannot access horizon even locally', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('horizon.index'))->assertForbidden();
});

test('administrators can access horizon', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get(route('horizon.index'))->assertOk();
});

test('registration cannot grant administrator access', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Regular User',
        'email' => 'regular@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'is_admin' => true,
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    expect(User::query()->where('email', 'regular@example.com')->firstOrFail()->is_admin)->toBeFalse();
});
