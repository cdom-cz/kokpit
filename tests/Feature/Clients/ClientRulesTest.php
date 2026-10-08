<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateClient as CreateClientAction;
use App\Domain\Clients\Actions\UpdateClient as UpdateClientAction;
use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Two rules that keep a client consistent with the rest of the data: the
 * currency is locked while a project of the client holds money, and a company
 * number belongs to one client per country (research item 3, D-09, D-15).
 * The placeholder 12345678 is fine here because the CZ checksum rule only
 * arrives in plan 04-14. Every name and amount is fictional.
 */

/**
 * The valid input of a client; overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rulesInput(array $overrides = []): array
{
    return [
        'name' => 'Example client rules',
        'country' => 'CZ',
        'stage' => 'active',
        'currency' => 'CZK',
        'hourly_rate' => '1500',
        'payment_terms_days' => 14,
        'invoice_language' => 'cs',
        ...$overrides,
    ];
}

/**
 * A second placeholder company number, assembled at runtime so no line looks like a real value.
 */
function rulesOtherNumber(): string
{
    return implode('', ['0000', '0001']);
}

/**
 * A project of the client written through the domain Action.
 *
 * @param  array<string, mixed>  $overrides
 */
function rulesProject(Client $client, array $overrides = []): void
{
    app(CreateProjectAction::class)->handle($client, [
        'name' => 'Example rules project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        ...$overrides,
    ]);
}

/**
 * The validation errors a callback raises, keyed by field; an empty list when it raises none.
 *
 * @return array<string, list<string>>
 */
function rulesErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

describe('currency lock', function (): void {
    it('refuses a currency change as a field error when a project holds an hourly rate and leaves the client unchanged', function (): void {
        $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);
        rulesProject($client, ['hourly_rate' => '850']);

        $errors = rulesErrors(fn () => app(UpdateClientAction::class)->handle($client, rulesInput(['currency' => 'EUR', 'hourly_rate' => '45'])));

        expect(array_keys($errors))->toBe(['currency'])
            ->and($errors['currency'][0])->toBe(__('kokpit.clients.errors.currency_locked'));

        $fresh = Client::query()->findOrFail($client->id);

        expect($fresh->currency)->toBe('CZK')
            ->and($fresh->hourly_rate_currency)->toBe('CZK')
            ->and($fresh->hourly_rate_minor)->toBe(100000);
    });

    it('refuses the change when the only money is on an archived project', function (): void {
        $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);
        app(CreateProjectAction::class)->handle($client, [
            'name' => 'Example archived project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '850',
        ])->delete();

        $errors = rulesErrors(fn () => app(UpdateClientAction::class)->handle($client, rulesInput(['currency' => 'EUR', 'hourly_rate' => '45'])));

        expect(array_keys($errors))->toBe(['currency'])
            ->and(Client::query()->findOrFail($client->id)->currency)->toBe('CZK');
    });

    it('refuses the change when a project holds a fixed price', function (): void {
        $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);
        rulesProject($client, ['billing_type' => 'fixed_price', 'fixed_price' => '12000']);

        expect(array_keys(rulesErrors(fn () => app(UpdateClientAction::class)->handle($client, rulesInput(['currency' => 'EUR', 'hourly_rate' => '45'])))))
            ->toBe(['currency']);
    });

    it('shows the lock on the currency field of the edit form', function (): void {
        $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);
        rulesProject($client, ['hourly_rate' => '850']);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['currency' => 'EUR', 'hourly_rate' => '45'])
            ->call('save')
            ->assertHasErrors(['data.currency']);

        expect(Client::query()->findOrFail($client->id)->currency)->toBe('CZK');
    });

    it('changes currency and rate together while no project holds money, keeping the rate in the client currency', function (): void {
        $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);
        // A project that falls back to the client rate holds no money of its own.
        rulesProject($client);

        app(UpdateClientAction::class)->handle($client, rulesInput(['currency' => 'EUR', 'hourly_rate' => '45,5']));

        $fresh = Client::query()->findOrFail($client->id);

        expect($fresh->currency)->toBe('EUR')
            ->and($fresh->hourly_rate_currency)->toBe('EUR')
            ->and($fresh->hourly_rate_minor)->toBe(4550);
    });

    it('still lets the other fields and the rate change while the currency stays', function (): void {
        $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);
        rulesProject($client, ['hourly_rate' => '850']);

        app(UpdateClientAction::class)->handle($client, rulesInput(['name' => 'Example client renamed', 'hourly_rate' => '1800']));

        $fresh = Client::query()->findOrFail($client->id);

        expect($fresh->name)->toBe('Example client renamed')
            ->and($fresh->currency)->toBe('CZK')
            ->and($fresh->hourly_rate_minor)->toBe(180000);
    });
});

describe('company number', function (): void {
    it('refuses a number that an active client in the same country holds, as a field error', function (): void {
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example holder', 'company_number' => '12345678']));

        $errors = rulesErrors(fn () => app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example newcomer', 'company_number' => '12345678'])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_taken'))
            ->and(Client::query()->where('name', 'Example newcomer')->exists())->toBeFalse();
    });

    it('says so when the holder is archived and points to restoring it', function (): void {
        $holder = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example archived holder', 'company_number' => '12345678']));
        $holder->delete();

        $errors = rulesErrors(fn () => app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example newcomer', 'company_number' => '12345678'])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_archived', ['name' => 'Example archived holder']))
            ->and($errors['company_number'][0])->not->toBe(__('kokpit.clients.errors.company_number_taken'));
    });

    it('accepts the same number in another country and clients without a number', function (): void {
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example holder', 'company_number' => '12345678']));

        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example abroad', 'country' => 'SK', 'company_number' => '12345678']));
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example without one', 'company_number' => null]));
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example without two', 'company_number' => null]));

        expect(Client::query()->count())->toBe(4);
    });

    it('applies the same rule on update and lets a client keep its own number', function (): void {
        $first = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example first', 'company_number' => '12345678']));
        $second = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example second', 'company_number' => rulesOtherNumber()]));

        $errors = rulesErrors(fn () => app(UpdateClientAction::class)->handle($second, rulesInput(['name' => 'Example second', 'company_number' => '12345678'])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_taken'))
            ->and(Client::query()->findOrFail($second->id)->company_number)->toBe(rulesOtherNumber());

        $first->delete();

        $archived = rulesErrors(fn () => app(UpdateClientAction::class)->handle($second, rulesInput(['name' => 'Example second', 'company_number' => '12345678'])));

        expect($archived['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_archived', ['name' => 'Example first']));

        $kept = app(UpdateClientAction::class)->handle($second, rulesInput(['name' => 'Example second renamed', 'company_number' => rulesOtherNumber()]));

        expect($kept->name)->toBe('Example second renamed')
            ->and($kept->company_number)->toBe(rulesOtherNumber());
    });

    it('shows the error on the company number field of the create form', function (): void {
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example holder', 'company_number' => '12345678']));

        Livewire::test(CreateClient::class)
            ->fillForm(rulesInput(['name' => 'Example newcomer', 'company_number' => '12345678']))
            ->call('create')
            ->assertHasErrors(['data.company_number']);

        expect(Client::query()->where('name', 'Example newcomer')->exists())->toBeFalse();
    });
});
