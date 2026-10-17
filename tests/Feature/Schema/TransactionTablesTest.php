<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\Canary;
use Tests\Support\RawSql;
use Tests\Support\Uuids;

/*
 * The storage end of the finance transactions (FI-01, D-12, D-13): one row per income or expense with its
 * amount, its CZK figure and the snapshot of the conversion, readable by the Admin only. The database rules
 * (types, keys, money and snapshot checks) follow in the later describe blocks. Names and amounts are
 * invented; no real business data.
 */

/**
 * The seeded root expense category "Software" of the starter set.
 */
function transactionTablesSoftwareCategory(): TransactionCategory
{
    return TransactionCategory::query()
        ->where('type', TransactionType::Expense->value)
        ->whereNull('parent_id')
        ->where('name', 'Software')
        ->sole();
}

describe('storing and reading a transaction', function (): void {
    it('stores a CZK expense with its CZK amount and gives the Admin the amount as Money', function (): void {
        $this->actingAs(Canary::admin());
        $counterparty = Canary::canary('counterparty');

        app(PartnerContext::class)->runAsSystem(static function () use ($counterparty): void {
            (new Transaction(['counterparty' => $counterparty, 'description' => 'Fictional licence']))->forceFill([
                'type' => TransactionType::Expense,
                'transaction_date' => '2026-10-14',
                'category_id' => transactionTablesSoftwareCategory()->getKey(),
                'amount' => Money::ofMinor(123450, 'CZK'),
                'czk_minor' => 123450,
                'czk_source' => 'identity',
            ])->save();
        });

        $transaction = Transaction::query()->where('counterparty', $counterparty)->sole();

        expect($transaction->type)->toBe(TransactionType::Expense)
            ->and($transaction->transaction_date->toDateString())->toBe('2026-10-14')
            ->and($transaction->amount)->toBeInstanceOf(Money::class)
            ->and($transaction->amount->equals(Money::ofMinor(123450, 'CZK')))->toBeTrue()
            ->and($transaction->czk_minor)->toBe(123450)
            ->and($transaction->czk_source)->toBe(CzkSource::Identity)
            ->and($transaction->exchange_rate)->toBeNull()
            ->and($transaction->exchange_unit_amount)->toBeNull()
            ->and($transaction->exchange_rate_date)->toBeNull()
            ->and($transaction->client_id)->toBeNull()
            ->and($transaction->project_id)->toBeNull()
            ->and($transaction->category->name)->toBe('Software');
    });
});

describe('Partner visibility', function (): void {
    it('shows a Partner with a client no transaction row', function (): void {
        [$clientA] = Canary::twoClients();
        app(PartnerContext::class)->runAsSystem(static function (): void {
            (new Transaction(['counterparty' => Canary::canary('counterparty')]))->forceFill([
                'type' => TransactionType::Expense,
                'transaction_date' => '2026-10-14',
                'category_id' => transactionTablesSoftwareCategory()->getKey(),
                'amount' => Money::ofMinor(5000, 'CZK'),
                'czk_minor' => 5000,
                'czk_source' => 'identity',
            ])->save();
        });

        $this->actingAs(Canary::partnerFor($clientA));

        expect(Transaction::query()->count())->toBe(0);
    });

    it('grants a Partner no ability on Transaction', function (): void {
        [$clientA] = Canary::twoClients();

        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);
        $row = app(PartnerContext::class)->runAsSystem(static function (): Transaction {
            $transaction = (new Transaction)->forceFill([
                'type' => TransactionType::Expense,
                'transaction_date' => '2026-10-14',
                'category_id' => transactionTablesSoftwareCategory()->getKey(),
                'amount' => Money::ofMinor(5000, 'CZK'),
                'czk_minor' => 5000,
                'czk_source' => 'identity',
            ]);
            $transaction->save();

            return $transaction;
        });

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, Transaction::class))->toBeFalse($ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, $row))->toBeFalse($ability);
        }
    });

    it('grants the Admin the abilities on Transaction', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);

        expect(Gate::forUser($admin)->allows('viewAny', Transaction::class))->toBeTrue();
    });
});

/**
 * Inserts one transaction row with valid defaults (a CZK expense, identity source, no client) by raw SQL and
 * returns its id. Overrides replace single columns; the column names come from the test only. The default id is
 * left to the database so the version 7 default is exercised.
 *
 * @param  array<string, mixed>  $overrides
 */
function transactionTablesInsert(array $overrides = []): string
{
    $row = [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => DB::table('transaction_categories')->where('type', 'expense')->whereNull('parent_id')->where('name', 'Software')->value('id'),
        'amount_minor' => 100000,
        'amount_currency' => 'CZK',
        'czk_minor' => 100000,
        'czk_source' => 'identity',
        'created_at' => '2026-10-16 10:00:00+00',
        'updated_at' => '2026-10-16 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into transactions (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * A fresh root category row of the given type, inserted by raw SQL, and its id.
 */
function transactionTablesCategory(string $type = 'expense'): string
{
    $id = (string) Str::uuid7();

    DB::insert(
        'insert into transaction_categories (id, type, depth, name, position, created_at, updated_at) values (?, ?, 0, ?, 1, now(), now())',
        [$id, $type, 'Fictional '.bin2hex(random_bytes(4))],
    );

    return $id;
}

describe('the primary key', function (): void {
    it('defaults the id of a row written by raw SQL to a version 7 uuid', function (): void {
        expect(transactionTablesInsert())->toMatch(Uuids::V7_PATTERN);
    });
});

describe('type, category and amount', function (): void {
    it('refuses a type other than income or expense', function (): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['type' => 'transfer']));
        RawSql::expectAllowed(fn () => transactionTablesInsert(['type' => 'expense']));
    });

    it('refuses a category of the other type, in both directions', function (): void {
        RawSql::expectSqlState('23503', fn () => transactionTablesInsert(['type' => 'income']));
        RawSql::expectSqlState('23503', fn () => transactionTablesInsert(['type' => 'expense', 'category_id' => transactionTablesCategory('income')]));
        RawSql::expectAllowed(fn () => transactionTablesInsert(['type' => 'income', 'category_id' => transactionTablesCategory('income')]));
    });

    it('refuses a category that does not exist', function (): void {
        RawSql::expectSqlState('23503', fn () => transactionTablesInsert(['category_id' => (string) Str::uuid7()]));
    });

    it('refuses an amount that is not above zero', function (int $amount): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['amount_minor' => $amount, 'czk_minor' => 100000]));
    })->with([
        'zero' => [0],
        'negative' => [-1],
    ]);

    it('refuses a malformed currency code', function (string $currency): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['amount_currency' => $currency]));
    })->with([
        'lower case' => ['czk'],
        'two letters' => ['EU'],
        'digits' => ['C2K'],
    ]);

    it('refuses a negative CZK figure but accepts zero for a tiny foreign amount', function (): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['czk_minor' => -1]));
        RawSql::expectAllowed(fn () => transactionTablesInsert([
            'amount_minor' => 1,
            'amount_currency' => 'EUR',
            'czk_minor' => 0,
            'czk_source' => 'cnb',
            'exchange_rate' => '25.1250000000',
            'exchange_unit_amount' => 1,
            'exchange_rate_date' => '2026-10-14',
        ]));
    });

    it('refuses a description over 2000 characters', function (): void {
        RawSql::expectAllowed(fn () => transactionTablesInsert(['description' => str_repeat('a', 2000)]));
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['description' => str_repeat('a', 2001)]));
    });
});

describe('the CZK figure and its snapshot', function (): void {
    /**
     * A valid CNB-converted EUR transaction; overrides replace single columns.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    $cnb = static fn (array $overrides = []): array => [
        'amount_minor' => 10000,
        'amount_currency' => 'EUR',
        'czk_minor' => 251250,
        'czk_source' => 'cnb',
        'exchange_rate' => '25.1250000000',
        'exchange_unit_amount' => 1,
        'exchange_rate_date' => '2026-10-14',
        ...$overrides,
    ];

    it('refuses an unknown source', function (): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['czk_source' => 'guess']));
    });

    it('accepts a CNB conversion with its complete snapshot and a manual figure without one', function () use ($cnb): void {
        RawSql::expectAllowed(fn () => transactionTablesInsert($cnb()));
        RawSql::expectAllowed(fn () => transactionTablesInsert($cnb(['czk_source' => 'manual', 'exchange_rate' => null, 'exchange_unit_amount' => null, 'exchange_rate_date' => null])));
    });

    it('refuses a CNB conversion with an incomplete or non-positive snapshot', function (array $overrides) use ($cnb): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($cnb($overrides)));
    })->with([
        'without a rate' => [['exchange_rate' => null]],
        'without a unit amount' => [['exchange_unit_amount' => null]],
        'without a rate date' => [['exchange_rate_date' => null]],
        'a zero rate' => [['exchange_rate' => '0']],
        'a zero unit amount' => [['exchange_unit_amount' => 0]],
    ]);

    it('refuses a snapshot on a row that is not a CNB conversion', function (array $overrides) use ($cnb): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($cnb($overrides)));
    })->with([
        'manual with a rate' => [['czk_source' => 'manual']],
        'identity with a rate' => [['amount_currency' => 'CZK', 'amount_minor' => 251250, 'czk_source' => 'identity']],
    ]);

    it('refuses an identity row whose CZK figure differs from the amount', function (): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['amount_minor' => 100000, 'czk_minor' => 99999]));
    });

    it('refuses a CZK row that is not identity and a foreign row that is', function () use ($cnb): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($cnb(['amount_currency' => 'CZK'])));
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['amount_currency' => 'EUR', 'czk_source' => 'identity']));
        RawSql::expectAllowed(fn () => transactionTablesInsert(['czk_source' => 'identity']));
    });
});

describe('the custom CZK source', function (): void {
    /**
     * A valid EUR transaction booked at an own rate; overrides replace single columns.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    $custom = static fn (array $overrides = []): array => [
        'amount_minor' => 10000,
        'amount_currency' => 'EUR',
        'czk_minor' => 251250,
        'czk_source' => 'custom',
        'exchange_rate' => '25.1250000000',
        'exchange_unit_amount' => 1,
        'exchange_rate_date' => '2026-10-14',
        ...$overrides,
    ];

    it('accepts an own rate with its complete positive snapshot', function () use ($custom): void {
        RawSql::expectAllowed(fn () => transactionTablesInsert($custom()));
        RawSql::expectAllowed(fn () => transactionTablesInsert($custom(['exchange_unit_amount' => 100])));
    });

    it('refuses an own rate with an incomplete or non-positive snapshot', function (array $overrides) use ($custom): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($custom($overrides)));
    })->with([
        'without a rate' => [['exchange_rate' => null]],
        'without a unit amount' => [['exchange_unit_amount' => null]],
        'without a rate date' => [['exchange_rate_date' => null]],
        'a zero rate' => [['exchange_rate' => '0']],
        'a zero unit amount' => [['exchange_unit_amount' => 0]],
    ]);

    it('refuses an own rate on a CZK row because the identity check is unchanged', function () use ($custom): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($custom(['amount_currency' => 'CZK'])));
    });

    it('still refuses a snapshot on a manual row and an unknown source', function () use ($custom): void {
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($custom(['czk_source' => 'manual'])));
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($custom(['czk_source' => 'guess'])));
    });

    it('turns own-rate rows into manual rows when the migration is rolled back', function () use ($custom): void {
        $id = transactionTablesInsert($custom());

        $migration = require database_path('migrations/2026_10_16_000600_add_custom_czk_source_to_transactions.php');
        $migration->down();

        /** @var object{czk_source: string, czk_minor: int, exchange_rate: string|null, exchange_unit_amount: int|null, exchange_rate_date: string|null} $row */
        $row = DB::selectOne('select czk_source, czk_minor, exchange_rate, exchange_unit_amount, exchange_rate_date from transactions where id = ?', [$id]);

        expect($row->czk_source)->toBe('manual')
            ->and($row->czk_minor)->toBe(251250)
            ->and($row->exchange_rate)->toBeNull()
            ->and($row->exchange_unit_amount)->toBeNull()
            ->and($row->exchange_rate_date)->toBeNull();

        RawSql::expectSqlState('23514', fn () => transactionTablesInsert($custom()));

        $migration->up();

        RawSql::expectAllowed(fn () => transactionTablesInsert($custom()));
    });
});

describe('client and project', function (): void {
    /**
     * Two fictional clients, one project of the first, and a client without a project.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    $clientsAndProject = static fn (): array => app(PartnerContext::class)->runAsSystem(static function (): array {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $clientA->id]);

        return [$clientA->id, $clientB->id, $project->id];
    });

    it('refuses a project without a client', function () use ($clientsAndProject): void {
        [, , $projectId] = $clientsAndProject();

        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['project_id' => $projectId]));
    });

    it('refuses a project of another client and accepts the project of the chosen client', function () use ($clientsAndProject): void {
        [$clientA, $clientB, $projectId] = $clientsAndProject();

        RawSql::expectSqlState('23503', fn () => transactionTablesInsert(['client_id' => $clientB, 'project_id' => $projectId]));
        RawSql::expectAllowed(fn () => transactionTablesInsert(['client_id' => $clientA, 'project_id' => $projectId]));
        RawSql::expectAllowed(fn () => transactionTablesInsert(['client_id' => $clientA]));
    });

    it('refuses a client that does not exist', function (): void {
        RawSql::expectSqlState('23503', fn () => transactionTablesInsert(['client_id' => (string) Str::uuid7()]));
    });
});

describe('deleting what a transaction references', function (): void {
    it('refuses to delete a category that a transaction uses', function (): void {
        $categoryId = transactionTablesCategory();
        $unusedId = transactionTablesCategory();
        transactionTablesInsert(['category_id' => $categoryId]);

        RawSql::expectSqlState('23503', fn () => DB::delete('delete from transaction_categories where id = ?', [$categoryId]));
        RawSql::expectAllowed(fn () => DB::delete('delete from transaction_categories where id = ?', [$unusedId]));
    });

    it('refuses to delete a client that a transaction references', function (): void {
        [$used, $unused] = app(PartnerContext::class)->runAsSystem(static fn (): array => [Client::factory()->create()->id, Client::factory()->create()->id]);
        transactionTablesInsert(['client_id' => $used]);

        RawSql::expectSqlState('23503', fn () => DB::delete('delete from clients where id = ?', [$used]));
        RawSql::expectAllowed(fn () => DB::delete('delete from clients where id = ?', [$unused]));
    });

    it('refuses to delete a project that a transaction references', function (): void {
        [$clientId, $projectId] = app(PartnerContext::class)->runAsSystem(static function (): array {
            $client = Client::factory()->create();

            return [$client->id, Project::factory()->create(['client_id' => $client->id])->id];
        });
        transactionTablesInsert(['client_id' => $clientId, 'project_id' => $projectId]);

        RawSql::expectSqlState('23503', fn () => DB::delete('delete from projects where id = ?', [$projectId]));
    });
});

/**
 * An issued invoice of the client, inserted complete by raw SQL, and its id.
 */
function transactionTablesIssuedInvoice(string $clientId, string $currency = 'CZK'): string
{
    static $sequence = 0;
    $sequence++;
    $number = '2026'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into invoices (client_id, kind, state, number, variable_symbol, currency, language, issue_date, due_date, subtotal_minor, total_minor, supplier_snapshot, customer_snapshot, bank_snapshot, appearance_snapshot, issued_at, created_at, updated_at) values (?, 'invoice', 'issued', ?, ?, ?, 'cs', '2026-10-17', '2026-10-31', 100000, 100000, '{}', '{}', '{}', '{}', '2026-10-17 10:30:00+00', now(), now()) returning id",
        [$clientId, $number, $number, $currency],
    );

    return $inserted->id;
}

/**
 * A payment of the invoice, inserted by raw SQL, and its id.
 */
function transactionTablesPayment(string $invoiceId, string $currency = 'CZK'): string
{
    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into invoice_payments (invoice_id, currency, amount_minor, paid_on, method, created_by, created_at, updated_at) values (?, ?, 40000, '2026-10-20', 'transfer', ?, now(), now()) returning id",
        [$invoiceId, $currency, app(PartnerContext::class)->runAsSystem(static fn (): string => User::factory()->create()->id)],
    );

    return $inserted->id;
}

/**
 * A client, an issued invoice of it, one payment and the income that books it.
 *
 * @return array{client: string, invoice: string, payment: string, income: string}
 */
function transactionTablesBooked(): array
{
    $client = app(PartnerContext::class)->runAsSystem(static fn (): string => Client::factory()->create()->id);
    $invoice = transactionTablesIssuedInvoice($client);
    $payment = transactionTablesPayment($invoice);
    $income = transactionTablesInsert([
        'type' => 'income',
        'category_id' => transactionTablesCategory('income'),
        'amount_minor' => 40000,
        'czk_minor' => 40000,
        'client_id' => $client,
        'invoice_id' => $invoice,
        'invoice_payment_id' => $payment,
    ]);

    return ['client' => $client, 'invoice' => $invoice, 'payment' => $payment, 'income' => $income];
}

/**
 * Deletes in the sanctioned order under the flag, as DeletePayment does, and forces the pair check.
 */
function transactionTablesSanctionedDelete(string $paymentId, string $incomeId): void
{
    DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$paymentId]);
    DB::delete('delete from transactions where id = ?', [$incomeId]);
    DB::delete('delete from invoice_payments where id = ?', [$paymentId]);
    DB::unprepared('SET CONSTRAINTS transactions_payment_income_pair_check IMMEDIATE');
    DB::select("select set_config('kokpit.payment_deletion', '', true)");
}

describe('the link to an invoice and to its payment', function (): void {
    it('lets an income of a client book an invoice and a payment of that invoice, once', function (): void {
        $booked = transactionTablesBooked();

        expect(DB::selectOne('select invoice_id, invoice_payment_id from transactions where id = ?', [$booked['income']]))
            ->invoice_id->toBe($booked['invoice'])
            ->invoice_payment_id->toBe($booked['payment']);

        RawSql::expectSqlState('23505', fn () => transactionTablesInsert([
            'type' => 'income',
            'category_id' => transactionTablesCategory('income'),
            'client_id' => $booked['client'],
            'invoice_id' => $booked['invoice'],
            'invoice_payment_id' => $booked['payment'],
        ]));
    });

    it('lets an income name an invoice of its client without a payment', function (): void {
        $client = app(PartnerContext::class)->runAsSystem(static fn (): string => Client::factory()->create()->id);
        $invoice = transactionTablesIssuedInvoice($client);

        RawSql::expectAllowed(fn () => transactionTablesInsert([
            'type' => 'income',
            'category_id' => transactionTablesCategory('income'),
            'client_id' => $client,
            'invoice_id' => $invoice,
        ]));
    });

    it('refuses an invoice on an expense, without a client, or of another client', function (): void {
        [$client, $other] = app(PartnerContext::class)->runAsSystem(static fn (): array => [Client::factory()->create()->id, Client::factory()->create()->id]);
        $invoice = transactionTablesIssuedInvoice($client);
        $income = ['type' => 'income', 'category_id' => transactionTablesCategory('income')];

        RawSql::expectSqlState('23514', fn () => transactionTablesInsert(['client_id' => $client, 'invoice_id' => $invoice]));
        RawSql::expectSqlState('23514', fn () => transactionTablesInsert([...$income, 'invoice_id' => $invoice]));
        RawSql::expectSqlState('23503', fn () => transactionTablesInsert([...$income, 'client_id' => $other, 'invoice_id' => $invoice]));
    });

    it('refuses a payment without its invoice and a payment of another invoice', function (): void {
        $booked = transactionTablesBooked();
        $otherInvoice = transactionTablesIssuedInvoice($booked['client']);
        $otherPayment = transactionTablesPayment($otherInvoice);
        $income = ['type' => 'income', 'category_id' => transactionTablesCategory('income'), 'client_id' => $booked['client']];

        RawSql::expectSqlState('23514', fn () => transactionTablesInsert([...$income, 'invoice_payment_id' => $otherPayment]));
        RawSql::expectSqlState('23503', fn () => transactionTablesInsert([...$income, 'invoice_id' => $booked['invoice'], 'invoice_payment_id' => $otherPayment]));
    });
});

describe('the income of a payment (ruling R-06-5)', function (): void {
    it('changes only in its date, its CZK figure and its category', function (): void {
        $booked = transactionTablesBooked();
        $update = fn (string $assignments, array $bindings = []) => fn () => DB::update("update transactions set $assignments where id = ?", [...$bindings, $booked['income']]);

        RawSql::expectAllowed($update("transaction_date = '2026-10-21'"));
        RawSql::expectAllowed($update('category_id = ?', [transactionTablesCategory('income')]));
        RawSql::expectAllowed($update("transaction_date = '2026-10-22', updated_at = '2030-01-01 00:00:00+00'"));

        $other = app(PartnerContext::class)->runAsSystem(static fn (): string => Client::factory()->create()->id);
        $anotherInvoice = transactionTablesIssuedInvoice($booked['client']);
        $anotherPayment = transactionTablesPayment($anotherInvoice);

        foreach ([
            ['amount_minor = 1, czk_minor = 1', []],
            ["amount_currency = 'EUR', czk_source = 'manual'", []],
            ["type = 'expense'", []],
            ['client_id = ?', [$other]],
            ['project_id = null, client_id = null', []],
            ["description = 'Changed'", []],
            ["counterparty = 'Changed'", []],
            ["document_number = 'Changed'", []],
            ['invoice_id = ?, invoice_payment_id = ?', [$anotherInvoice, $anotherPayment]],
            ['invoice_payment_id = null', []],
            ['invoice_id = null, invoice_payment_id = null', []],
        ] as [$assignments, $bindings]) {
            RawSql::expectSqlState('KP001', $update($assignments, $bindings));
        }

        RawSql::expectSqlState('KP001', function () use ($booked): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$booked['payment']]);
            DB::update('update transactions set amount_minor = 1, czk_minor = 1 where id = ?', [$booked['income']]);
        });
    });

    it('follows the date with the CZK figure of a foreign payment', function (): void {
        $client = app(PartnerContext::class)->runAsSystem(static fn (): string => Client::factory()->create()->id);
        $invoice = transactionTablesIssuedInvoice($client, 'EUR');
        $payment = transactionTablesPayment($invoice, 'EUR');
        $income = transactionTablesInsert([
            'type' => 'income',
            'category_id' => transactionTablesCategory('income'),
            'amount_minor' => 40000,
            'amount_currency' => 'EUR',
            'czk_minor' => 1000000,
            'czk_source' => 'cnb',
            'exchange_rate' => '25.0000000000',
            'exchange_unit_amount' => 1,
            'exchange_rate_date' => '2026-10-20',
            'client_id' => $client,
            'invoice_id' => $invoice,
            'invoice_payment_id' => $payment,
        ]);

        RawSql::expectAllowed(fn () => DB::update(
            "update transactions set transaction_date = '2026-10-21', czk_minor = 1004000, exchange_rate = 25.1, exchange_rate_date = '2026-10-21' where id = ?",
            [$income],
        ));
        RawSql::expectSqlState('KP001', fn () => DB::update('update transactions set amount_minor = 40001 where id = ?', [$income]));
    });

    it('refuses an existing transaction becoming the income of a payment, and leaves an ordinary income editable and deletable', function (): void {
        $booked = transactionTablesBooked();
        $secondPayment = transactionTablesPayment($booked['invoice']);
        $ordinary = transactionTablesInsert([
            'type' => 'income',
            'category_id' => transactionTablesCategory('income'),
            'client_id' => $booked['client'],
            'invoice_id' => $booked['invoice'],
        ]);

        RawSql::expectSqlState('KP001', fn () => DB::update('update transactions set invoice_payment_id = ? where id = ?', [$secondPayment, $ordinary]));
        RawSql::expectAllowed(fn () => DB::update("update transactions set amount_minor = 5, czk_minor = 5, description = 'Edited' where id = ?", [$ordinary]));
        RawSql::expectAllowed(fn () => DB::delete('delete from transactions where id = ?', [$ordinary]));
        RawSql::expectAllowed(fn () => DB::delete('delete from transactions where id = ?', [transactionTablesInsert()]));
    });

    it('is deleted only under the flag that names its payment', function (): void {
        $booked = transactionTablesBooked();
        $anotherPayment = transactionTablesPayment($booked['invoice']);

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from transactions where id = ?', [$booked['income']]));
        RawSql::expectSqlState('KP001', function () use ($booked): void {
            DB::unprepared("SET LOCAL kokpit.unrelated_setting = 'on'");
            DB::delete('delete from transactions where id = ?', [$booked['income']]);
        });
        RawSql::expectSqlState('KP001', function () use ($booked, $anotherPayment): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$anotherPayment]);
            DB::delete('delete from transactions where id = ?', [$booked['income']]);
        });
        RawSql::expectSqlState('KP001', function () use ($booked): void {
            DB::select("select set_config('kokpit.payment_deletion', '', true)");
            DB::delete('delete from transactions where invoice_id = ?', [$booked['invoice']]);
        });

        expect((int) DB::selectOne('select count(*) as n from transactions where id = ?', [$booked['income']])->n)->toBe(1);
    });
});

describe('a payment and its income go together (ruling R-06-7)', function (): void {
    it('deletes both in the sanctioned order', function (): void {
        $booked = transactionTablesBooked();

        RawSql::expectAllowed(fn () => transactionTablesSanctionedDelete($booked['payment'], $booked['income']));

        expect((int) DB::selectOne('select count(*) as n from transactions where id = ?', [$booked['income']])->n)->toBe(0)
            ->and((int) DB::selectOne('select count(*) as n from invoice_payments where id = ?', [$booked['payment']])->n)->toBe(0);
    });

    it('refuses to delete the payment while its income exists', function (): void {
        $booked = transactionTablesBooked();

        RawSql::expectSqlState('23503', function () use ($booked): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$booked['payment']]);
            DB::delete('delete from invoice_payments where id = ?', [$booked['payment']]);
        });
    });

    it('refuses at commit an income deleted while its payment still exists', function (): void {
        $booked = transactionTablesBooked();

        RawSql::expectSqlState('KP001', function () use ($booked): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$booked['payment']]);
            DB::delete('delete from transactions where id = ?', [$booked['income']]);
            DB::unprepared('SET CONSTRAINTS transactions_payment_income_pair_check IMMEDIATE');
        });

        expect((int) DB::selectOne('select count(*) as n from transactions where id = ?', [$booked['income']])->n)->toBe(1);
    });

    it('proves the income guard by a mutation: without it a bare DELETE is accepted', function (): void {
        $booked = transactionTablesBooked();
        $bareDelete = fn () => RawSql::expectSqlState('KP001', fn () => DB::delete('delete from transactions where id = ?', [$booked['income']]));

        $bareDelete();

        DB::unprepared('DROP TRIGGER transactions_payment_income_guard ON transactions');

        expect($bareDelete)->toThrow(AssertionFailedError::class, 'was accepted');
    });

    it('proves the pair check by a mutation: without it an income deleted alone is accepted', function (): void {
        $booked = transactionTablesBooked();
        $incomeAlone = fn () => RawSql::expectSqlState('KP001', function () use ($booked): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$booked['payment']]);
            DB::delete('delete from transactions where id = ?', [$booked['income']]);
            DB::unprepared('SET CONSTRAINTS ALL IMMEDIATE');
        });

        $incomeAlone();

        DB::unprepared('DROP TRIGGER transactions_payment_income_pair_check ON transactions');

        expect($incomeAlone)->toThrow(AssertionFailedError::class, 'was accepted');
    });
});
