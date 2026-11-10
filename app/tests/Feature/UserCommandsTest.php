<?php

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

afterEach(function () {
    foreach (['ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'ADMIN_PASSWORD_FILE'] as $name) {
        putenv($name);
    }
});

test('user:create creates a verified account', function () {
    $this->artisan('user:create', ['--name' => 'Stefan', '--email' => 'Admin@Example.com', '--password' => 'correct-horse-battery'])
        ->assertSuccessful();

    $user = User::where('email', 'admin@example.com')->firstOrFail();
    expect($user->name)->toBe('Stefan')
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('user:create rejects weak passwords and duplicate emails', function () {
    $this->artisan('user:create', ['--name' => 'A', '--email' => 'a@example.com', '--password' => 'short'])->assertFailed();

    User::factory()->create(['email' => 'taken@example.com']);
    $this->artisan('user:create', ['--name' => 'B', '--email' => 'taken@example.com', '--password' => 'long-enough-password'])->assertFailed();

    expect(User::count())->toBe(1);
});

test('user:create asks for missing details interactively', function () {
    $this->artisan('user:create')
        ->expectsQuestion('Name', 'Admin')
        ->expectsQuestion('Email address', 'admin@example.com')
        ->expectsQuestion('Password (at least 8 characters)', 'correct-horse-battery')
        ->assertSuccessful();

    expect(User::where('email', 'admin@example.com')->exists())->toBeTrue();
});

test('--from-env creates the first admin only when no users exist', function () {
    putenv('ADMIN_NAME=Owner');
    putenv('ADMIN_EMAIL=owner@example.com');
    putenv('ADMIN_PASSWORD=correct-horse-battery');

    $this->artisan('user:create', ['--from-env' => true])->assertSuccessful();
    expect(User::where('email', 'owner@example.com')->value('name'))->toBe('Owner');

    // Second start: ignored, no duplicate, password not overwritten.
    putenv('ADMIN_PASSWORD=another-password-123');
    $this->artisan('user:create', ['--from-env' => true])->assertSuccessful();
    expect(User::count())->toBe(1)
        ->and(Hash::check('correct-horse-battery', User::first()->password))->toBeTrue();
});

test('--from-env closes the registration window for strangers', function () {
    putenv('ADMIN_EMAIL=owner@example.com');
    putenv('ADMIN_PASSWORD=correct-horse-battery');
    config(['app.registration_enabled' => false]);

    $this->artisan('user:create', ['--from-env' => true])->assertSuccessful();

    $this->get('/register')->assertForbidden();
});

test('--from-env reads the password from a file (Docker secrets)', function () {
    $file = tempnam(sys_get_temp_dir(), 'pw');
    file_put_contents($file, "secret-from-file-123\n");
    putenv('ADMIN_EMAIL=owner@example.com');
    putenv("ADMIN_PASSWORD_FILE={$file}");

    $this->artisan('user:create', ['--from-env' => true])->assertSuccessful();
    unlink($file);

    expect(Hash::check('secret-from-file-123', User::first()->password))->toBeTrue();
});

test('--from-env does nothing when not configured and fails when half configured', function () {
    $this->artisan('user:create', ['--from-env' => true])->assertSuccessful();
    expect(User::count())->toBe(0);

    putenv('ADMIN_EMAIL=owner@example.com');
    $this->artisan('user:create', ['--from-env' => true])->assertFailed();
    expect(User::count())->toBe(0);
});

test('user:reset-password sets a new password and can remove 2FA', function () {
    $user = User::factory()->create(['email' => 'locked@example.com']);
    app(TwoFactorAuthenticationService::class)->enable($user, (new Google2FA)->generateSecretKey(32));
    DB::table('passkeys')->insert([
        'authenticatable_id' => $user->id, 'name' => 'Lost phone', 'credential_id' => 'x', 'data' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('user:reset-password', ['email' => 'locked@example.com', '--password' => 'brand-new-password'])->assertSuccessful();
    $user->refresh();
    expect(Hash::check('brand-new-password', $user->password))->toBeTrue()
        ->and($user->hasTwoFactorEnabled())->toBeTrue();

    $this->artisan('user:reset-password', ['email' => 'locked@example.com', '--password' => 'brand-new-password', '--disable-2fa' => true])->assertSuccessful();
    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->passkeys()->count())->toBe(0);

    $this->artisan('user:reset-password', ['email' => 'nobody@example.com', '--password' => 'brand-new-password'])->assertFailed();
});
