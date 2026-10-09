<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * For a Filament page that hands its form data to a domain Action.
 *
 * Domain Actions throw a ValidationException keyed by the bare data key (`key`,
 * `hourly_rate`, `client_id`). The page form lives under the Livewire state path
 * `data`, so a bare key would not land next to its field. `withFormErrors()` runs
 * the Action and rethrows the same messages with every key prefixed by `data.`.
 */
trait RethrowsDomainValidation
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws ValidationException with the keys prefixed by the form state path
     */
    protected function withFormErrors(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $key => $errors) {
                $messages[str_starts_with($key, 'data.') ? $key : 'data.'.$key] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }
}
