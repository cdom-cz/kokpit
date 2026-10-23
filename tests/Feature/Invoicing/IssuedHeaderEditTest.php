<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * The header of an open issued invoice or proforma in the editor (IN-13, IN-15; D-06, D-13; assumptions A5, A6, A9,
 * A14): the dates, the language, the customer and supplier lines and the bank account. Every name, address and number
 * is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    Mail::getSymfonyTransport()->flush();
});

/**
 * The editor of a document.
 */
function hedEditor(Invoice $invoice): Testable
{
    return Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The document row, read fresh.
 */
function hedRow(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * An issued invoice that was sent once (so it was locked) and opened again with a reason.
 */
function hedSentAndUnlocked(?Invoice $issued = null): Invoice
{
    $invoice = $issued ?? InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);

    $row = app(QueueInvoiceEmail::class)->handle(test()->admin, $invoice, [
        'recipient' => 'jane@example.com',
        'subject' => 'Example invoice',
        'body' => 'Example body',
        'attach_work_report' => false,
    ]);

    SendInvoiceEmail::dispatchSync($row->id);

    expect(hedRow($invoice)->lock_state)->toBe(InvoiceLockState::Locked);

    return app(UnlockInvoice::class)->handle(test()->admin, $invoice, 'Wrong example due date and customer name');
}

/**
 * The text of the one stored PDF, white space reduced; the test is incomplete without Ghostscript.
 */
function hedPdfText(): string
{
    $files = Storage::disk('local')->allFiles();

    expect($files)->toHaveCount(1);

    $text = PdfText::flat(Storage::disk('local')->get($files[0]) ?? '');

    if ($text === null) {
        test()->markTestIncomplete('Ghostscript is not installed, the printed text cannot be read back.');
    }

    return $text;
}

/**
 * The rows of the activity log of a document of one event, oldest first.
 *
 * @return list<Activity>
 */
function hedAudit(Invoice $invoice, string $event = 'invoice_edited'): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

describe('the tracer: a due date and a customer name corrected in the editor', function (): void {
    it('stores both, prints both on the new PDF and lists both in the history of a sent document', function (): void {
        $invoice = hedSentAndUnlocked();
        $editor = hedEditor($invoice);

        $editor->assertFormSet(['due_date' => '2026-10-28', 'customer.name' => hedRow($invoice)->customer_snapshot['name']])
            ->set('data.due_date', '2026-11-20')
            ->set('data.customer.name', 'Corrected Example Client')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice]));

        $row = hedRow($invoice);
        $text = hedPdfText();
        $changes = hedAudit($invoice)[0]->attribute_changes?->all() ?? [];

        expect($row->due_date?->format('Y-m-d'))->toBe('2026-11-20')
            ->and($row->customer_snapshot['name'])->toBe('Corrected Example Client')
            ->and($row->number)->toBe($invoice->number)
            ->and($text)->toContain('Corrected Example Client')
            ->and($text)->toContain('20. 11. 2026')
            ->and(hedAudit($invoice))->toHaveCount(1)
            ->and($changes['attributes'])->toBe(['due_date' => '2026-11-20', 'customer.name' => 'Corrected Example Client'])
            ->and($changes['old']['due_date'])->toBe('2026-10-28');
    });
});

describe('the customer lines', function (): void {
    it('keeps a field the input does not mention, ignores an unknown key and trims what it takes', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $before = hedRow($invoice)->customer_snapshot;

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'customer' => ['name' => '  Renamed Example Client  ', 'street' => '', 'unknown_key' => 'x', 'country' => 'sk'],
        ]);

        $after = hedRow($invoice)->customer_snapshot;

        expect($after['name'])->toBe('Renamed Example Client')
            ->and($after['street'])->toBeNull()
            ->and($after['country'])->toBe('SK')
            ->and($after['city'])->toBe($before['city'])
            ->and($after['company_id'])->toBe($before['company_id'])
            ->and($after)->not->toHaveKey('unknown_key');
    });

    it('refuses a name that is blank or too long and a country that is not two capitals, and writes nothing', function (string $field, mixed $value, string $message): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $row = hedRow($invoice);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['customer' => [$field => $value]]);
            $this->fail('The customer line should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey("customer.{$field}")
                ->and($exception->errors()["customer.{$field}"][0])->toBe($message);
        }

        expect(hedRow($invoice)->customer_snapshot)->toBe($row->customer_snapshot);
    })->with([
        'blank name' => ['name', '   ', 'Vyplňte název.'],
        'name of 256 characters' => ['name', str_repeat('a', 256), 'Zadejte nejvýše 255 znaků.'],
        'country of three letters' => ['country', 'CZE', 'Zadejte kód země ze dvou velkých písmen, například CZ.'],
        'postal code of 21 characters' => ['postal_code', str_repeat('1', 21), 'Zadejte nejvýše 20 znaků.'],
        'a list instead of text' => ['city', ['a'], 'Hodnota nemá platný formát.'],
    ]);

    it('shows a refused customer line under its own field in the editor', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        hedEditor($invoice)
            ->set('data.customer.name', str_repeat('a', 256))
            ->call('save')
            ->assertHasFormErrors(['customer.name']);

        expect(hedRow($invoice)->customer_snapshot['name'])->not->toStartWith('aaaa');
    });
});

describe('the due date and the language', function (): void {
    it('refuses a due date before the issue date and one that is not a day', function (mixed $due): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        hedEditor($invoice)
            ->set('data.due_date', $due)
            ->call('save')
            ->assertHasFormErrors(['due_date']);

        expect(hedRow($invoice)->due_date?->format('Y-m-d'))->toBe('2026-10-28');
    })->with([
        'before the issue date' => ['2026-10-13'],
        'not a day' => ['not-a-date'],
    ]);

    it('answers a due date before the issue date with the existing copy', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['due_date' => '2026-10-13']);
            $this->fail('The due date should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['due_date'])->toBe(['Datum splatnosti nesmí být dříve než datum vystavení.']);
        }
    });

    it('stores the language and prints the English copy on the new PDF', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        hedEditor($invoice)->set('data.language', 'en')->call('save')->assertHasNoFormErrors();

        $text = hedPdfText();

        expect(hedRow($invoice)->language->value)->toBe('en')
            ->and($text)->toContain('INVOICE')
            ->and($text)->toContain('Due date')
            ->and($text)->toContain('2026-10-28');
    });

    it('refuses a language that is not offered', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['language' => 'de']);
            $this->fail('The language should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('language');
        }

        expect(hedRow($invoice)->language->value)->toBe('cs');
    });

    it('writes no history row for a header edit of a document that was never sent, like a draft', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['due_date' => '2026-11-02']);

        expect(hedAudit($invoice))->toBe([])
            ->and(hedRow($invoice)->due_date?->format('Y-m-d'))->toBe('2026-11-02');
    });
});
