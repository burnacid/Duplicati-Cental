<?php

use App\Livewire\Settings\Passkeys;
use App\Livewire\Settings\TwoFactor;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use PragmaRX\Google2FA\Google2FA;

function enableTotp(User $user): string
{
    $secret = (new Google2FA())->generateSecretKey(32);
    app(TwoFactorAuthenticationService::class)->enable($user, $secret);

    return $secret;
}

/** Passkey rows need real WebAuthn data for the model cast; tests only need the row. */
function insertPasskey(User $user, string $name): object
{
    $id = \Illuminate\Support\Facades\DB::table('passkeys')->insertGetId([
        'authenticatable_id' => $user->id, 'name' => $name, 'credential_id' => $name, 'data' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (object) ['id' => $id];
}

function otp(string $secret, int $offsetSteps = 0): string
{
    $g = new Google2FA();

    return $g->oathTotp($secret, $g->getTimestamp() + $offsetSteps);
}

test('a user can set up TOTP and gets recovery codes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(TwoFactor::class)->call('startSetup')->assertSee('Scan this QR code');
    $secret = session('two_factor.pending_secret');
    expect($secret)->not->toBeNull();

    $component->set('code', '000000')->call('confirmSetup')->assertHasErrors('code');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $component->set('code', otp($secret))->call('confirmSetup')->assertHasNoErrors()->assertSee('Save your recovery codes');

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_recovery_codes)->toHaveCount(8)
        ->and(\Illuminate\Support\Facades\DB::table('users')->value('two_factor_secret'))->not->toBe($secret); // encrypted at rest
});

test('login with TOTP asks for a code before signing in', function () {
    $user = User::factory()->create();
    $secret = enableTotp($user);

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('two-factor.challenge', absolute: false));

    $this->assertGuest();

    Volt::test('auth.two-factor-challenge')
        ->set('code', '123456')
        ->call('verify')
        ->assertHasErrors('code');
    $this->assertGuest();

    Volt::test('auth.two-factor-challenge')
        ->set('code', otp($secret))
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('the same TOTP code cannot be used twice', function () {
    $user = User::factory()->create();
    $secret = enableTotp($user);
    $service = app(TwoFactorAuthenticationService::class);
    $code = otp($secret);

    expect($service->verify($user, $code))->toBeTrue()
        ->and($service->verify($user->fresh(), $code))->toBeFalse();
});

test('a recovery code works exactly once', function () {
    $user = User::factory()->create();
    enableTotp($user);
    $code = $user->fresh()->two_factor_recovery_codes[0];

    session()->put('login.two_factor', ['id' => $user->id, 'remember' => false, 'expires_at' => now()->addMinutes(5)->timestamp]);
    Volt::test('auth.two-factor-challenge')->call('toggleRecovery')->set('code', strtoupper($code))->call('verify')->assertHasNoErrors();
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    session()->put('login.two_factor', ['id' => $user->id, 'remember' => false, 'expires_at' => now()->addMinutes(5)->timestamp]);
    Volt::test('auth.two-factor-challenge')->call('toggleRecovery')->set('code', $code)->call('verify')->assertHasErrors('code');

    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(7);
});

test('the code prompt is rate limited and expires', function () {
    $user = User::factory()->create();
    enableTotp($user);

    session()->put('login.two_factor', ['id' => $user->id, 'remember' => false, 'expires_at' => now()->addMinutes(5)->timestamp]);
    $component = Volt::test('auth.two-factor-challenge');
    foreach (range(1, 5) as $i) {
        $component->set('code', '000000')->call('verify');
    }
    $component->set('code', '000000')->call('verify')->assertHasErrors('code');
    expect($component->errors()->first('code'))->toStartWith('Too many attempts');

    session()->put('login.two_factor', ['id' => $user->id, 'remember' => false, 'expires_at' => now()->subMinute()->timestamp]);
    $this->get(route('two-factor.challenge'))->assertRedirect(route('login', absolute: false));
});

test('users without TOTP sign in with their password as before', function () {
    $user = User::factory()->create();

    Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('the security page requires password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.security'))->assertRedirect(route('password.confirm'));

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.security'))
        ->assertOk()
        ->assertSee('Authenticator app')
        ->assertSee('Passkeys');
});

test('disabling TOTP removes the secret and recovery codes', function () {
    $user = User::factory()->create();
    enableTotp($user);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test(TwoFactor::class)->call('disable');

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull();
});

test('REQUIRE_2FA sends users without TOTP or passkey to the security page', function () {
    config(['auth.require_two_factor' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('settings.security'));
    $this->actingAs($user)->get(route('settings.profile'))->assertOk();

    enableTotp($user);
    $this->actingAs($user->fresh())->get(route('home'))->assertOk();
});

test('REQUIRE_2FA is satisfied by a passkey too', function () {
    config(['auth.require_two_factor' => true]);
    $user = User::factory()->create();
    insertPasskey($user, 'Laptop');

    $this->actingAs($user)->get(route('home'))->assertOk();
});

test('login page offers passkey sign-in and the options endpoint works', function () {
    config(['app.url' => 'https://central.example.test', 'passkeys.relying_party.id' => 'central.example.test']);

    $this->get(route('login'))->assertOk()->assertSee('Sign in with passkey');

    $options = $this->getJson(route('passkeys.authentication_options'))->assertOk()->json();
    expect($options)->toHaveKey('challenge')
        ->and($options['rpId'])->toBe('central.example.test');
});

test('an invalid passkey assertion does not sign anyone in', function () {
    $this->getJson(route('passkeys.authentication_options'));

    $this->from(route('login'))
        ->post(route('passkeys.login'), ['start_authentication_response' => json_encode(['id' => 'nope'])])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('users can only delete their own passkeys', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $bobsKey = insertPasskey($bob, 'Bob phone');
    $alicesKey = insertPasskey($alice, 'Alice laptop');

    $this->actingAs($alice)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test(Passkeys::class)
        ->assertSee('Alice laptop')
        ->assertDontSee('Bob phone')
        ->call('deletePasskey', $bobsKey->id)
        ->call('deletePasskey', $alicesKey->id);

    expect($bob->passkeys()->count())->toBe(1)
        ->and($alice->passkeys()->count())->toBe(0);
});

test('a cancelled passkey prompt shows a clear message', function () {
    $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test(Passkeys::class)
        ->call('registrationFailed', 'NotAllowedError')
        ->assertSee('cancelled or timed out');
});
