<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * Account recovery from the command line (e.g. when email is not configured):
 *
 *   docker exec -it duplicati-central php artisan user:reset-password you@example.com
 *   docker exec -it duplicati-central php artisan user:reset-password you@example.com --disable-2fa
 */
class ResetUserPasswordCommand extends Command
{
    protected $signature = 'user:reset-password
        {email : Email address of the account}
        {--password= : New password (asked for when omitted)}
        {--disable-2fa : Also turn off the authenticator app and remove all passkeys (lost device)}';

    protected $description = 'Set a new password for an account, optionally removing its two-factor methods';

    public function handle(TwoFactorAuthenticationService $twoFactor): int
    {
        $user = User::query()->where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No account with that email address.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: password('New password (at least 8 characters)', required: true);

        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::min(8)]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password, 'remember_token' => null])->save();

        // Sign the account out everywhere (database sessions only).
        if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->info("New password set for {$user->email}.");

        if ($this->option('disable-2fa')) {
            $twoFactor->disable($user);
            $count = $user->passkeys()->count();
            $user->passkeys()->delete();
            $this->info("Two-factor authentication turned off and {$count} passkey(s) removed.");
        }

        return self::SUCCESS;
    }
}
