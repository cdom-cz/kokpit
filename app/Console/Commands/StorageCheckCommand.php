<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\Storage\StorageCheck;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Console\Command;

/**
 * Proves on a server that the private S3-compatible storage works (D-17).
 *
 * Output is limited to the step, the endpoint host, the object path and the
 * exception class with the provider's error code. A signed URL is a credential
 * for its lifetime, so it is never printed, and neither is any configuration value.
 */
class StorageCheckCommand extends Command
{
    protected $signature = 'kokpit:storage:check {--disk=s3 : Name of the filesystem disk to check}';

    protected $description = 'Upload, read through a temporary URL, refuse an unsigned read and delete a test object on the S3 disk';

    public function handle(StorageCheck $check): int
    {
        // Console work has no signed-in user, so the fail-closed scopes would
        // silence it: the whole run is an explicit system run.
        return app(PartnerContext::class)->runAsSystem(fn (): int => $this->check($check));
    }

    private function check(StorageCheck $check): int
    {
        $disk = (string) $this->option('disk');

        if (config('filesystems.disks.'.$disk.'.driver') !== 's3') {
            $this->components->error(__('kokpit.storage_check.not_s3', ['disk' => $disk]));

            return self::FAILURE;
        }

        $this->components->info(__('kokpit.storage_check.heading'));
        $this->line(__('kokpit.storage_check.endpoint', ['host' => $check->endpointHost($disk)]));

        $steps = $check->run($disk);

        $this->line(__('kokpit.storage_check.object', ['path' => (string) $check->path()]));

        $failed = null;

        foreach ($steps as $step) {
            $label = __('kokpit.storage_check.steps.'.$step->name);

            if ($step->ok) {
                $this->components->info(__('kokpit.storage_check.step_ok', [
                    'step' => $label,
                    'ms' => $step->milliseconds,
                ]));

                continue;
            }

            $failed ??= $step;

            $this->components->error(__('kokpit.storage_check.step_failed', [
                'step' => $label,
                'ms' => $step->milliseconds,
                'error' => (string) $step->error,
            ]));
        }

        if ($failed !== null) {
            $this->components->error(__('kokpit.storage_check.failed', [
                'step' => __('kokpit.storage_check.steps.'.$failed->name),
            ]));

            return self::FAILURE;
        }

        $this->components->info(__('kokpit.storage_check.passed'));

        return self::SUCCESS;
    }
}
