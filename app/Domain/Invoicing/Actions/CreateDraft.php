<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates an empty draft invoice or proforma for exactly one client (IN-02, IN-08, D-01, D-11): the one checked
 * entry point of a new draft. The Admin only.
 *
 * Data keys: `client_id`, `kind` (`invoice` or `proforma`), `currency` (optional) and `source` (`empty`; the plan that
 * adds time to a draft adds its own source). The currency defaults to the client's currency, the language to the
 * client's invoice language and the VAT mode to the current setting. The draft has no number, and its issue and due
 * dates stay null until the Admin types them: a null issue date means "today (Europe/Prague) at issue" and a null due
 * date "the issue date plus the client's payment terms at issue" (R-11-1). A missing bank account for the currency
 * is a blocker of issuing, not an error of the draft (R-11-2).
 *
 * The Partner-readable `invoices` row and its Admin-only `invoice_admin` row are written together, while the document
 * is a draft: after issue the database accepts no new child row.
 */
final readonly class CreateDraft
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);

        $source = $data['source'] ?? 'empty';

        if ($source !== 'empty') {
            throw new InvalidArgumentException('A draft is created from an empty source; time is added by its own Action.');
        }

        $kind = $this->kind($data['kind'] ?? null);

        return DB::transaction(function () use ($data, $kind): Invoice {
            $client = $this->client($data['client_id'] ?? null);
            $currency = $this->currency($data['currency'] ?? null, $client);

            $draft = new Invoice;
            $draft->forceFill([
                'client_id' => $client->id,
                'kind' => $kind,
                'currency' => $currency,
                'language' => $client->invoice_language,
                'vat_mode' => app(InvoicingSettings::class)->vat_mode,
            ])->save();

            $admin = new InvoiceAdmin;
            $admin->forceFill(['invoice_id' => $draft->id])->save();

            return $draft->refresh();
        });
    }

    /**
     * @throws ValidationException `kind`
     */
    private function kind(mixed $value): InvoiceKind
    {
        $kind = $value instanceof InvoiceKind ? $value : (is_string($value) ? InvoiceKind::tryFrom($value) : null);

        if ($kind === null || $kind === InvoiceKind::CreditNote) {
            throw ValidationException::withMessages(['kind' => __('invoices.errors.kind')]);
        }

        return $kind;
    }

    /**
     * The client row, read under a share lock so an archive cannot slip in before the draft is written.
     *
     * @throws ValidationException `client_id`
     */
    private function client(mixed $id): Client
    {
        $client = is_string($id) && $id !== ''
            ? Client::query()->withTrashed()->whereKey($id)->sharedLock()->first()
            : null;

        if (! $client instanceof Client) {
            throw ValidationException::withMessages(['client_id' => __('invoices.errors.client_unknown')]);
        }

        if ($client->trashed()) {
            throw ValidationException::withMessages(['client_id' => __('invoices.errors.client_archived')]);
        }

        return $client;
    }

    /**
     * @throws ValidationException `currency`
     */
    private function currency(mixed $value, Client $client): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $client->currency;
        }

        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (! Money::isKnownCurrency($code)) {
            throw ValidationException::withMessages(['currency' => __('invoices.errors.currency')]);
        }

        return $code;
    }
}
