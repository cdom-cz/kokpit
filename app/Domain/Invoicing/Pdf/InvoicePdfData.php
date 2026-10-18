<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Money\Money;
use Closure;
use DateTimeInterface;
use LogicException;
use UnexpectedValueException;

/**
 * Everything the invoice template prints, read from the issued document and nothing else (D-21, D-23; ruling
 * R-15-4). The sources are the columns of the `invoices` row, the `invoice_items` rows and the four frozen
 * snapshots (supplier, customer, bank, appearance). It never reads the client, the supplier or invoicing
 * settings or the exchange rates, and from the Admin-only row only the frozen pay token of the card button (Phase 11
 * D-01), so a later change of any of them cannot alter a regenerated PDF, and the CNB rate, the CZK total, the
 * internal note and linked time cannot reach the page (UI-SPEC U-16).
 *
 * The logo and the signature come from {@see DocumentImage::dataUri()} with the path and SHA-256 of the
 * appearance snapshot, which throws when the stored object is missing or changed, so the regeneration is either
 * equivalent to the issued document or fails.
 *
 * All words are resolved here in the language of the document, every amount through {@see Money::format()}
 * and dates in the language's format, so the template only places strings; Blade escapes each of them.
 */
final readonly class InvoicePdfData
{
    private const int LOGO_MAX_WIDTH = 160;

    private const int LOGO_MAX_HEIGHT = 40;

    private const int SIGNATURE_MAX_WIDTH = 200;

    private const int SIGNATURE_MAX_HEIGHT = 80;

    /**
     * @param  array{heading: string, number: string}  $header
     * @param  array{label: string, name: string, lines: list<string>, ids: list<array{key: string, value: string}>, notes: list<string>, vatNote: string|null}  $supplier
     * @param  array{label: string, name: string, lines: list<string>, ids: list<array{key: string, value: string}>, notes: list<string>, vatNote: string|null}  $customer
     * @param  list<array{label: string|null, value: string, strong: bool}>  $dates
     * @param  array{quantity: string, unitPrice: string, total: string, vatRate: string|null}  $columns
     * @param  list<array{index: int, description: string, quantity: string, unitPrice: string, total: string, vatRate: string|null}>  $items
     * @param  array{label: string, amount: string}  $summary
     * @param  array{qrDataUri: string|null, sentence: list<array{text: string, bold: bool}>, facts: list<array{key: string, value: string}>}|null  $payment
     * @param  array{lead: string, label: string, url: string}|null  $cardPayment
     * @param  array{uri: string, width: float, height: float}|null  $logo
     * @param  array{uri: string, width: float, height: float}|null  $signature
     * @param  list<string>  $footer
     */
    private function __construct(
        public InvoiceKind $kind,
        public InvoiceLanguage $language,
        public array $header,
        public DocumentPalette $palette,
        public ?array $logo,
        public array $supplier,
        public array $customer,
        public ?string $proformaNote,
        public array $dates,
        public array $columns,
        public array $items,
        public array $summary,
        public ?string $note,
        public ?array $payment,
        public ?array $cardPayment,
        public ?string $creditNoteNotice,
        public array $footer,
        public ?array $signature,
        public string $pageTemplate,
        public string $title,
    ) {}

    /**
     * @throws LogicException when the document is not issued or lacks a snapshot
     * @throws UnexpectedValueException when a snapshotted image is missing or no longer matches its hash
     */
    public static function fromInvoice(Invoice $issued): self
    {
        if (! $issued->isIssued()) {
            throw new LogicException('Only an issued document has a PDF.');
        }

        $language = $issued->language;
        $locale = $language->value;
        $kind = $issued->kind;
        $number = $issued->number ?? throw new LogicException('An issued document has a number.');
        $supplier = $issued->supplier_snapshot ?? throw new LogicException('An issued document has a supplier snapshot.');
        $customer = $issued->customer_snapshot ?? throw new LogicException('An issued document has a customer snapshot.');
        $appearance = $issued->appearance_snapshot ?? throw new LogicException('An issued document has an appearance snapshot.');

        $t = static fn (string $key, array $replace = []): string => trans('invoice_pdf.'.$key, $replace, $locale);
        $date = static fn (?DateTimeInterface $day): string => $day === null ? '' : $day->format($language === InvoiceLanguage::Czech ? 'j. n. Y' : 'Y-m-d');
        $payer = $issued->vat_mode === VatMode::Payer;

        $palette = DocumentPalette::fromAccent(self::text($appearance['accent'] ?? null));
        $images = app(DocumentImage::class);
        $payment = $kind->carriesPayment() ? self::payment($issued, $t, $date) : null;

        return new self(
            kind: $kind,
            language: $language,
            header: ['heading' => $t('headings.'.$kind->value), 'number' => $number],
            palette: $palette,
            logo: self::image($images, $appearance['logo'] ?? null, self::LOGO_MAX_WIDTH, self::LOGO_MAX_HEIGHT),
            supplier: self::party($t('supplier'), $supplier, $t, $payer ? null : $t('not_vat_payer'), true),
            customer: self::party($t('customer'), $customer, $t, null, false),
            proformaNote: $kind === InvoiceKind::Proforma ? $t('proforma_note') : null,
            dates: self::dates($issued, $t, $date, $payer),
            columns: [
                'quantity' => $t('quantity'),
                'unitPrice' => $t('unit_price'),
                'total' => $t('total'),
                'vatRate' => $payer ? $t('vat_rate') : null,
            ],
            items: self::items($issued, $language, $payer),
            summary: [
                'label' => $t($kind === InvoiceKind::CreditNote ? 'credit_total_bar' : 'amount_due_bar'),
                'amount' => $issued->total()->format($locale),
            ],
            note: self::text($issued->note),
            payment: $payment,
            cardPayment: $payment === null ? null : self::cardPayment($issued, $t),
            creditNoteNotice: $kind === InvoiceKind::CreditNote ? $t('credit_note_no_payment') : null,
            footer: self::footer(self::text($appearance['footer'] ?? null)),
            signature: self::image($images, $appearance['signature'] ?? null, self::SIGNATURE_MAX_WIDTH, self::SIGNATURE_MAX_HEIGHT),
            pageTemplate: $t('page', ['n' => '{n}', 'total' => '{total}']),
            title: $t('headings.'.$kind->value).' '.$number,
        );
    }

    /**
     * One party block of a snapshot: address lines, the company and VAT ids, and the notes.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  Closure(string, array<string, string>=): string  $t
     * @return array{label: string, name: string, lines: list<string>, ids: list<array{key: string, value: string}>, notes: list<string>, vatNote: string|null}
     */
    private static function party(string $label, array $snapshot, Closure $t, ?string $vatNote, bool $withContact): array
    {
        $lines = [];

        if (($street = self::text($snapshot['street'] ?? null)) !== null) {
            $lines[] = $street;
        }

        $city = trim(implode(' ', array_filter([self::text($snapshot['postal_code'] ?? null), self::text($snapshot['city'] ?? null)])));

        if ($city !== '') {
            $lines[] = $city;
        }

        $country = self::text($snapshot['country'] ?? null);

        if ($country !== null && $country !== 'CZ') {
            $lines[] = $country;
        }

        $ids = [];

        if (($companyId = self::text($snapshot['company_id'] ?? null)) !== null) {
            $ids[] = ['key' => $t('company_id'), 'value' => $companyId];
        }

        if (($vatId = self::text($snapshot['vat_id'] ?? null)) !== null) {
            $ids[] = ['key' => $t('vat_id'), 'value' => $vatId];
        }

        $notes = [];

        foreach (['registration_note', ...($withContact ? ['email', 'phone', 'website'] : [])] as $key) {
            if (($value = self::text($snapshot[$key] ?? null)) !== null) {
                $notes[] = $value;
            }
        }

        return [
            'label' => $label,
            'name' => self::text($snapshot['name'] ?? null) ?? '',
            'lines' => $lines,
            'ids' => $ids,
            'notes' => $notes,
            'vatNote' => $vatNote,
        ];
    }

    /**
     * The date strip: issue date, the taxable supply date for a VAT payer, then the due date; a credit note
     * names the document it corrects instead of a due date.
     *
     * @param  Closure(string, array<string, string>=): string  $t
     * @param  Closure(DateTimeInterface|null): string  $date
     * @return list<array{label: string|null, value: string, strong: bool}>
     */
    private static function dates(Invoice $issued, Closure $t, Closure $date, bool $payer): array
    {
        $cells = [['label' => $t('issue_date'), 'value' => $date($issued->issue_date), 'strong' => true]];

        if ($payer && $issued->taxable_supply_date !== null) {
            $cells[] = ['label' => $t('taxable_supply_date'), 'value' => $date($issued->taxable_supply_date), 'strong' => true];
        }

        if ($issued->kind === InvoiceKind::CreditNote) {
            $corrected = $issued->creditedInvoice?->number;

            if ($corrected !== null) {
                $cells[] = ['label' => null, 'value' => $t('corrects_document', ['number' => $corrected]), 'strong' => false];
            }

            return $cells;
        }

        $cells[] = ['label' => $t('due_date'), 'value' => $date($issued->due_date), 'strong' => true];

        return $cells;
    }

    /**
     * @return list<array{index: int, description: string, quantity: string, unitPrice: string, total: string, vatRate: string|null}>
     */
    private static function items(Invoice $issued, InvoiceLanguage $language, bool $payer): array
    {
        $locale = $language->value;
        $rows = [];

        foreach ($issued->items as $index => $item) {
            $quantity = $item->quantity()->format($locale);
            $rows[] = [
                'index' => $index + 1,
                'description' => $item->description,
                'quantity' => trim($quantity.' '.$item->unit),
                'unitPrice' => Money::ofMinor($item->unit_price_minor, $issued->currency)->format($locale),
                'total' => Money::ofMinor($item->line_total_minor, $issued->currency)->format($locale),
                'vatRate' => $payer && $item->vat_rate !== null ? (string) $item->vat_rate : null,
            ];
        }

        return $rows;
    }

    /**
     * The payment box of an invoice or proforma, null when the bank snapshot is absent. The QR is printed only
     * for a CZK document paid to a Czech IBAN, with the variable symbol and the due date at hand.
     *
     * @param  Closure(string, array<string, string>=): string  $t
     * @param  Closure(DateTimeInterface|null): string  $date
     * @return array{qrDataUri: string|null, sentence: list<array{text: string, bold: bool}>, facts: list<array{key: string, value: string}>}|null
     */
    private static function payment(Invoice $issued, Closure $t, Closure $date): ?array
    {
        $bank = $issued->bank_snapshot;

        if ($bank === null) {
            return null;
        }

        $locale = $issued->language->value;
        $iban = self::text($bank['iban'] ?? null);
        $accountNumber = self::text($bank['account_number'] ?? null);
        $bankCode = self::text($bank['bank_code'] ?? null);
        $bic = self::text($bank['bic'] ?? null);
        $variableSymbol = $issued->variable_symbol;
        $dueDate = $issued->due_date;
        $amount = $issued->total()->format($locale);

        $account = $accountNumber !== null
            ? $accountNumber.($bankCode !== null ? '/'.$bankCode : '')
            : ($iban === null ? '' : self::groupIban($iban));

        $qr = null;

        if ($variableSymbol !== null && $dueDate !== null && $iban !== null && $issued->total()->isPositive() && Spayd::supports($issued->currency, $iban)) {
            $qr = QrPng::dataUri(Spayd::forPayment(
                $iban,
                $issued->total(),
                $variableSymbol,
                $dueDate->format('Y-m-d'),
                $t('qr_message', ['number' => (string) $issued->number]),
            ));
        }

        $facts = [];

        if ($accountNumber !== null) {
            $facts[] = ['key' => $t('account'), 'value' => $account];
        }

        if ($iban !== null) {
            $facts[] = ['key' => $t('iban'), 'value' => self::groupIban($iban)];
        }

        if ($bic !== null) {
            $facts[] = ['key' => $t('bic'), 'value' => $bic];
        }

        if ($variableSymbol !== null) {
            $facts[] = ['key' => $t('variable_symbol'), 'value' => $variableSymbol];
        }

        $facts[] = ['key' => $t('payment_method'), 'value' => $t('bank_transfer')];
        $facts[] = ['key' => $t('amount_due'), 'value' => $amount];

        return [
            'qrDataUri' => $qr,
            'sentence' => self::sentence($t('payment_sentence', [
                'amount' => "\u{1}".$amount."\u{1}",
                'account' => "\u{1}".$account."\u{1}",
                'vs' => "\u{1}".($variableSymbol ?? '')."\u{1}",
                'due' => "\u{1}".$date($dueDate)."\u{1}",
            ])),
            'facts' => $facts,
        ];
    }

    /**
     * The "Pay by card" button of the payment box (D-01, D-05, D-06; ruling R11-05-1), null when the document was not
     * issued with card payment on. It reads the frozen pay token of the Admin-only row and nothing else, never the
     * live Stripe link or the payment state, so a PDF regenerated after the document is paid or the link replaced
     * prints the same button and URL (R11-05-2, Phase 10 D-23). The URL is the stable Kokpit one, never a Stripe URL.
     *
     * @param  Closure(string, array<string, string>=): string  $t
     * @return array{lead: string, label: string, url: string}|null
     */
    private static function cardPayment(Invoice $issued, Closure $t): ?array
    {
        $url = PayUrl::forInvoice($issued);

        if ($url === null) {
            return null;
        }

        return ['lead' => $t('card_lead'), 'label' => $t('card_button'), 'url' => $url];
    }

    /**
     * Splits the payment sentence, in which the filled-in values are fenced by a control character, into plain and
     * bold parts, so the template prints each one escaped without any raw output.
     *
     * @return list<array{text: string, bold: bool}>
     */
    private static function sentence(string $fenced): array
    {
        $parts = [];

        foreach (explode("\u{1}", $fenced) as $position => $text) {
            if ($text !== '') {
                $parts[] = ['text' => $text, 'bold' => $position % 2 === 1];
            }
        }

        return $parts;
    }

    /**
     * An IBAN in groups of four for reading.
     */
    private static function groupIban(string $iban): string
    {
        return trim(chunk_split(preg_replace('/\s+/', '', $iban) ?? $iban, 4, ' '));
    }

    /**
     * @return list<string>
     */
    private static function footer(?string $footer): array
    {
        if ($footer === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $footer) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * An image of the appearance snapshot as a data URI, sized to fit the box without upscaling.
     *
     * @return array{uri: string, width: float, height: float}|null
     */
    private static function image(DocumentImage $images, mixed $reference, int $maxWidth, int $maxHeight): ?array
    {
        if (! is_array($reference) || ! is_string($reference['path'] ?? null) || ! is_string($reference['sha256'] ?? null)) {
            return null;
        }

        $uri = $images->dataUri($reference['path'], $reference['sha256']);
        $size = getimagesizefromstring((string) base64_decode(substr($uri, strlen('data:image/png;base64,')), true));

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new UnexpectedValueException('document image mismatch');
        }

        $scale = min(1.0, $maxWidth / $size[0], $maxHeight / $size[1]);

        return ['uri' => $uri, 'width' => round($size[0] * $scale, 2), 'height' => round($size[1] * $scale, 2)];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
