<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Create a user from the command line, e.g. the first admin of a new installation:
 *
 *   docker exec -it duplicati-central php artisan user:create
 *
 * With --from-env it reads ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD (or ADMIN_PASSWORD_FILE)
 * from the environment and only acts when there are no users yet (used at container start-up).
 */
class CreateUserCommand extends Command
{
    protected $signature = 'user:create
        {--name= : Display name}
        {--email= : Email address (used to sign in)}
        {--password= : Password (asked for when omitted; avoid passing it on the command line)}
        {--from-env : Read ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD / ADMIN_PASSWORD_FILE and only create when no users exist}';

    protected $description = 'Create a user account (for example the first admin)';

    public function handle(): int
    {
        if ($this->option('from-env')) {
            return $this->createFromEnvironment();
        }

        $name = $this->option('name') ?: text('Name', default: 'Admin', required: true);
        $email = $this->option('email') ?: text('Email address', required: true);
        $password = $this->option('password') ?: password('Password (at least 8 characters)', required: true);

        return $this->create($name, $email, $password);
    }

    private function createFromEnvironment(): int
    {
        // getenv(): the configuration may be cached, so env() would return null here.
        $email = (string) getenv('ADMIN_EMAIL');
        $password = (string) getenv('ADMIN_PASSWORD');

        if ($password === '' && ($file = getenv('ADMIN_PASSWORD_FILE')) && is_readable($file)) {
            $password = trim((string) file_get_contents($file));
        }

        if ($email === '' && $password === '') {
            return self::SUCCESS; // nothing configured
        }

        if (User::query()->exists()) {
            $this->line('Users already exist; ADMIN_EMAIL/ADMIN_PASSWORD are ignored. You can remove them from the configuration.');

            return self::SUCCESS;
        }

        if ($email === '' || $password === '') {
            $this->error('Set both ADMIN_EMAIL and ADMIN_PASSWORD (or ADMIN_PASSWORD_FILE) to create the first user.');

            return self::FAILURE;
        }

        return $this->create((string) (getenv('ADMIN_NAME') ?: 'Admin'), $email, $password);
    }

    private function create(string $name, string $email, string $password): int
    {
        $validator = Validator::make(
            ['name' => $name, 'email' => strtolower($email), 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(8)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $user = User::query()->create($data);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("User {$user->email} created. Sign in and set up two-factor authentication under Settings → Security.");

        return self::SUCCESS;
    }
}
