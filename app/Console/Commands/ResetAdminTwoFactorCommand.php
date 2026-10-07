<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

/**
 * Clears the TOTP secret and recovery codes of a user (D-07).
 *
 * This is the only recovery for a lost authenticator device and for a secret
 * that became undecryptable after APP_KEY was changed. It needs shell access to
 * the server, so there is deliberately no web equivalent. The audit line carries
 * the user id and never the e-mail address.
 */
class ResetAdminTwoFactorCommand extends Command
{
    protected $signature = 'kokpit:admin:reset-2fa {email : E-mail address of the user} {--force : Reset without asking}';

    protected $description = 'Clear the two-factor authentication of a user (lost device or rotated APP_KEY)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            $this->components->error(__('kokpit.reset_2fa.not_found'));

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->components->error(__('kokpit.reset_2fa.force_required'));

                return self::FAILURE;
            }

            if (! confirm(label: __('kokpit.reset_2fa.confirm', ['email' => $user->email]), default: false)) {
                $this->components->warn(__('kokpit.reset_2fa.aborted'));

                return self::FAILURE;
            }
        }

        // Not fillable on purpose; only trusted code like this command writes them.
        $user->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        Log::warning('Two-factor authentication reset from the CLI', ['user_id' => $user->id]);

        $this->components->info(__('kokpit.reset_2fa.done'));

        return self::SUCCESS;
    }
}
