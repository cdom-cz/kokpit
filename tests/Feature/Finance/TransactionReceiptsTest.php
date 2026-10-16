<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentOwnerResolver;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Filament\RelationManagers\TransactionDocumentsRelationManager;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * Receipts as files of a transaction (FI-01, DO-01, DO-03, D-11, D-12, D-14, D-21): the Soubory tab of the
 * transaction, the client and project of a receipt following its transaction, the receipt count and the
 * "without receipt" filter, and the deletion of the receipts with the transaction. Everything is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * A client-visible project of the given client, written through the domain Action.
 */
function receiptProject(Client $client, string $key = 'RCP'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example receipt project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * A recorded expense, with the client and project when given.
 *
 * @param  array<string, mixed>  $overrides
 */
function receiptTransaction(?Client $client = null, ?Project $project = null, array $overrides = []): Transaction
{
    $admin = auth()->user();
    assert($admin instanceof User);

    $parent = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->where('name', 'Software')->sole();
    $category = TransactionCategory::query()->where('parent_id', $parent->getKey())->where('name', 'Hosting')->sole();

    return app(RecordTransaction::class)->handle($admin, [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => $category->getKey(),
        'amount' => '100,00',
        'currency' => 'CZK',
        'counterparty' => Canary::canary('counterparty'),
        'client_id' => $client?->getKey(),
        'project_id' => $project?->getKey(),
        ...$overrides,
    ]);
}

/**
 * The Soubory tab as the Admin sees it on the edit page of the transaction.
 */
function receiptsTab(Transaction $transaction): Testable
{
    return Livewire::test(TransactionDocumentsRelationManager::class, ['ownerRecord' => $transaction, 'pageClass' => EditTransaction::class]);
}

/**
 * Attaches fictional receipts straight through the domain Action.
 *
 * @return list<Document>
 */
function attachReceipts(Transaction $transaction, int $count = 1): array
{
    $admin = auth()->user();
    assert($admin instanceof User);

    $files = [];

    for ($i = 1; $i <= $count; $i++) {
        $files[] = DocumentFixtures::upload('receipt-example-'.$i.'.txt');
    }

    return array_values(app(AttachDocuments::class)->handle($admin, $transaction, $files)->all());
}

describe('the tracer: the Admin attaches a receipt on the Soubory tab of a transaction', function (): void {
    it('registers the receipts tab on the transaction resource', function (): void {
        expect(TransactionResource::getRelations())->toContain(TransactionDocumentsRelationManager::class)
            ->and(DocumentOwnerType::Transaction->value)->toBe('transaction')
            ->and(DocumentOwnerType::Transaction->isShareable())->toBeFalse()
            ->and(DocumentOwnerType::Transaction->getLabel())->toBe('Transakce');
    });

    it('stores an uploaded receipt owned by the transaction with its client and project, hidden, without a visibility switch', function (): void {
        $client = Client::factory()->create();
        $project = receiptProject($client);
        $transaction = receiptTransaction($client, $project);

        $tab = receiptsTab($transaction)->mountAction(TestAction::make('upload')->table());
        $tab->upload('mountedActions.0.data.files.'.Str::uuid()->toString(), [UploadedFile::fake()->createWithContent('receipt-example.txt', implode(' ', ['Fictional', 'receipt', Str::random(8)]))]);
        $tab->assertSchemaComponentHidden('visible_to_client')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $document = Document::query()->sole();

        expect($document->owner_type)->toBe('transaction')
            ->and($document->owner_id)->toBe($transaction->id)
            ->and($document->client_id)->toBe($client->id)
            ->and($document->project_id)->toBe($project->id)
            ->and($document->visible_to_client)->toBeFalse()
            ->and($document->uploaded_by_id)->toBe($this->admin->id);

        receiptsTab($transaction)
            ->assertCanSeeTableRecords([$document])
            ->assertTableActionHidden('toggleVisibility', $document);
    });

    it('titles the tab with the receipts copy', function (): void {
        $transaction = receiptTransaction();

        expect(TransactionDocumentsRelationManager::getTitle($transaction, EditTransaction::class))->toBe(__('finance.transactions.receipts.relation_title'));
    });

    it('redirects a download click to a signed link for the Admin', function (): void {
        $transaction = receiptTransaction();
        [$document] = attachReceipts($transaction);

        receiptsTab($transaction)
            ->callAction(TestAction::make('download')->table($document))
            ->assertRedirect();
    });

    it('never lets a Partner of that client read a receipt', function (): void {
        $client = Client::factory()->create();
        $transaction = receiptTransaction($client, receiptProject($client));
        attachReceipts($transaction);

        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(Document::query()->count())->toBe(0);
    });

    it('resolves a transaction owner to its client and project and never to a shareable one', function (): void {
        $client = Client::factory()->create();
        $project = receiptProject($client);

        $withBoth = app(DocumentOwnerResolver::class)->resolve(receiptTransaction($client, $project));
        $without = app(DocumentOwnerResolver::class)->resolve(receiptTransaction());

        expect($withBoth)->toMatchArray(['owner_type' => 'transaction', 'client_id' => $client->id, 'project_id' => $project->id, 'shareable' => false, 'default_visible' => false])
            ->and($without)->toMatchArray(['owner_type' => 'transaction', 'client_id' => null, 'project_id' => null, 'shareable' => false, 'default_visible' => false]);
    });
});
