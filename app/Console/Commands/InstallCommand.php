<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the single Admin (D-05).
 *
 * The password is never an argument or option: interactive runs ask for it
 * with a hidden prompt, automation passes it in the KOKPIT_ADMIN_PASSWORD
 * environment variable. It is read through Env, not config(), so a one-shot
 * secret is never cached into the configuration. Nothing here prints or logs it.
 */
class InstallCommand extends Command
{
    protected $signature = 'kokpit:install {--name= : Display name of the Admin} {--email= : E-mail address of the Admin}';

    protected $description = 'Create the single Admin account of this Kokpit instance';

    /** bcrypt silently ignores every byte beyond this length. */
    private const int PASSWORD_MAX_BYTES = 72;

    public function handle(): int
    {
        // Console work has no signed-in user, so the fail-closed scopes would
        // silence it (Pitfall 8): the whole run is an explicit system run.
        return app(PartnerContext::class)->runAsSystem(fn (): int => $this->install());
    }

    private function install(): int
    {
        // Cheap early refusal so a second run does not prompt for anything.
        // The authoritative check runs again under the advisory lock below.
        if ($this->adminAlreadyExists()) {
            return $this->refuse('kokpit.install.admin_exists');
        }

        $name = $this->stringOption('name');
        $email = $this->stringOption('email');

        if ($this->input->isInteractive()) {
            $name = $name !== '' ? $name : text(label: __('kokpit.install.prompt_name'), required: true);
            $email = $email !== '' ? $email : text(label: __('kokpit.install.prompt_email'), required: true);
            $password = password(label: __('kokpit.install.prompt_password'), required: true);
            $confirmation = password(label: __('kokpit.install.prompt_password_confirmation'), required: true);

            if ($password !== $confirmation) {
                return $this->refuse('kokpit.install.password_mismatch');
            }
        } else {
            if ($name === '' || $email === '') {
                return $this->refuse('kokpit.install.name_email_required');
            }

            $password = (string) Env::get('KOKPIT_ADMIN_PASSWORD');

            if ($password === '') {
                return $this->refuse('kokpit.install.password_env_missing');
            }
        }

        $name = trim($name);
        $email = Str::lower(trim($email));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => [
                    'required',
                    'string',
                    Password::min(12),
                    static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (strlen((string) $value) > self::PASSWORD_MAX_BYTES) {
                            $fail(__('kokpit.install.password_too_long'));
                        }
                    },
                ],
            ],
            [],
            (array) __('kokpit.install.attributes'),
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($name, $email, $password): bool {
            // Serialises two installs started at the same instant.
            DB::statement("select pg_advisory_xact_lock(hashtext('kokpit:install'))");

            // Both roles must exist before the first role query: the role scope
            // throws on a database that has none.
            foreach (RoleName::cases() as $role) {
                Role::findOrCreate($role->value, 'web');
            }

            if (User::role(RoleName::Admin->value)->exists()) {
                return false;
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->assignRole(RoleName::Admin->value);

            return true;
        });

        if (! $created) {
            return $this->refuse('kokpit.install.admin_exists');
        }

        $this->components->info(__('kokpit.install.created'));
        $this->line(__('kokpit.install.login_url', ['url' => url('/admin')]));

        return self::SUCCESS;
    }

    /**
     * Role-agnostic on purpose: before the first install the roles do not exist yet.
     */
    private function adminAlreadyExists(): bool
    {
        return User::query()
            ->whereHas('roles', static fn ($roles) => $roles->where('name', RoleName::Admin->value))
            ->exists();
    }

    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }

    private function refuse(string $messageKey): int
    {
        $this->components->error(__($messageKey));

        return self::FAILURE;
    }
}
