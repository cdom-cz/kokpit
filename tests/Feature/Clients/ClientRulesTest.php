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
use Tests\Support\FictionalCompanyId;

/*
 * Two rules that keep a client consistent with the rest of the data: the
 * currency is locked while a project of the client holds money, and a company
 * number belongs to one client per country (research item 3, D-09, D-15), and
 * a Czech company number passes the mod-11 check in the Actions as well as in the
 * form (D-09). Czech numbers are generated at test time by FictionalCompanyId;
 * every name and amount is fictional.
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
 * A checksum-valid Czech company number that differs from the given one.
 */
function rulesOtherNumber(string $not): string
{
    do {
        $number = FictionalCompanyId::valid();
    } while ($number === $not);

    return $number;
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
        $number = FictionalCompanyId::valid();
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example holder', 'company_number' => $number]));

        $errors = rulesErrors(fn () => app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example newcomer', 'company_number' => $number])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_taken'))
            ->and(Client::query()->where('name', 'Example newcomer')->exists())->toBeFalse();
    });

    it('says so when the holder is archived and points to restoring it', function (): void {
        $number = FictionalCompanyId::valid();
        $holder = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example archived holder', 'company_number' => $number]));
        $holder->delete();

        $errors = rulesErrors(fn () => app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example newcomer', 'company_number' => $number])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_archived', ['name' => 'Example archived holder']))
            ->and($errors['company_number'][0])->not->toBe(__('kokpit.clients.errors.company_number_taken'));
    });

    it('accepts the same number in another country and clients without a number', function (): void {
        $number = FictionalCompanyId::valid();
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example holder', 'company_number' => $number]));

        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example abroad', 'country' => 'SK', 'company_number' => $number]));
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example without one', 'company_number' => null]));
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example without two', 'company_number' => null]));

        expect(Client::query()->count())->toBe(4);
    });

    it('applies the same rule on update and lets a client keep its own number', function (): void {
        $number = FictionalCompanyId::valid();
        $other = rulesOtherNumber($number);
        $first = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example first', 'company_number' => $number]));
        $second = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example second', 'company_number' => $other]));

        $errors = rulesErrors(fn () => app(UpdateClientAction::class)->handle($second, rulesInput(['name' => 'Example second', 'company_number' => $number])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_taken'))
            ->and(Client::query()->findOrFail($second->id)->company_number)->toBe($other);

        $first->delete();

        $archived = rulesErrors(fn () => app(UpdateClientAction::class)->handle($second, rulesInput(['name' => 'Example second', 'company_number' => $number])));

        expect($archived['company_number'][0])->toBe(__('kokpit.clients.errors.company_number_archived', ['name' => 'Example first']));

        $kept = app(UpdateClientAction::class)->handle($second, rulesInput(['name' => 'Example second renamed', 'company_number' => $other]));

        expect($kept->name)->toBe('Example second renamed')
            ->and($kept->company_number)->toBe($other);
    });

    it('shows the error on the company number field of the create form', function (): void {
        $number = FictionalCompanyId::valid();
        app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example holder', 'company_number' => $number]));

        Livewire::test(CreateClient::class)
            ->fillForm(rulesInput(['name' => 'Example newcomer', 'company_number' => $number]))
            ->call('create')
            ->assertHasErrors(['data.company_number']);

        expect(Client::query()->where('name', 'Example newcomer')->exists())->toBeFalse();
    });
});

describe('Czech company number checksum', function (): void {
    it('refuses a Czech number with a wrong check digit in CreateClient as a field error and stores nothing', function (string $number): void {
        $errors = rulesErrors(fn () => app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example bad number', 'company_number' => $number])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.ares.errors.invalid_id'))
            ->and(Client::query()->where('name', 'Example bad number')->exists())->toBeFalse();
    })->with([
        'placeholder' => ['12345678'],
        'generated wrong digit' => fn () => FictionalCompanyId::invalid(),
        'seven digits' => ['1234567'],
        'letters' => ['abcdefgh'],
        'inner space' => ['1234 5678'],
    ]);

    it('reports the company number together with other field errors', function (): void {
        $errors = rulesErrors(fn () => app(CreateClientAction::class)->handle(rulesInput(['company_number' => '12345678', 'name' => ''])));

        expect(array_keys($errors))->toEqualCanonicalizing(['company_number', 'name']);
    });

    it('refuses the same on UpdateClient and leaves the stored number unchanged', function (): void {
        $kept = FictionalCompanyId::valid();
        $client = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example update', 'company_number' => $kept]));

        $errors = rulesErrors(fn () => app(UpdateClientAction::class)->handle($client, rulesInput(['name' => 'Example update', 'company_number' => FictionalCompanyId::invalid()])));

        expect(array_keys($errors))->toBe(['company_number'])
            ->and($errors['company_number'][0])->toBe(__('kokpit.ares.errors.invalid_id'))
            ->and(Client::query()->findOrFail($client->id)->company_number)->toBe($kept);
    });

    it('accepts a checksum-valid Czech number and trims it before the check', function (): void {
        $number = FictionalCompanyId::valid();

        $client = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example valid number', 'company_number' => '  '.$number.' ']));

        expect($client->company_number)->toBe($number);
    });

    it('accepts any text as the company number of a client of another country', function (string $country, string $text): void {
        $client = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example abroad free', 'country' => $country, 'company_number' => $text]));

        expect($client->company_number)->toBe($text);

        $updated = app(UpdateClientAction::class)->handle($client, rulesInput(['name' => 'Example abroad free', 'country' => $country, 'company_number' => $text.' x']));

        expect($updated->company_number)->toBe($text.' x');
    })->with([
        'Germany' => ['DE', 'HRB 000 example'],
        'Slovakia' => ['SK', '12345678'],
        'Poland' => ['PL', 'PL-123'],
    ]);

    it('reads a lower-case country the way the Action normalises it', function (): void {
        $errors = rulesErrors(fn () => app(CreateClientAction::class)->handle(rulesInput(['country' => 'cz', 'company_number' => '12345678'])));

        expect(array_keys($errors))->toBe(['company_number']);
    });

    it('accepts a missing company number for a Czech client', function (): void {
        $client = app(CreateClientAction::class)->handle(rulesInput(['name' => 'Example no number', 'company_number' => null]));

        expect($client->company_number)->toBeNull();
    });
});
