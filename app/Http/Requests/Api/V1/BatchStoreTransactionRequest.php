<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The validated envelope of POST /api/v1/transactions/batch (D-04, D-05).
 *
 * Only the envelope is checked here: `transactions` is a list of 1 to `kokpit.api.transaction_batch_max_items`
 * items. The items themselves are validated one by one in the controller, so one bad item never fails the whole
 * batch.
 */
final class BatchStoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        return [
            'transactions' => ['required', 'array', 'list', self::atMostItems()],
        ];
    }

    /**
     * The largest batch (`kokpit.api.transaction_batch_max_items`), read when the request is validated so a test or
     * an operator change takes effect at once. An empty list is already refused by `required`. This is a closure
     * rather than `max:n` so Scribe does not append its untranslated rule sentence to the documented field.
     */
    private static function atMostItems(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $max = (int) config('kokpit.api.transaction_batch_max_items');

            if (is_array($value) && count($value) > $max) {
                $fail(__('validation.max.array', ['max' => $max]));
            }
        };
    }
}
