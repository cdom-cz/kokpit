<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentOwnerResolver;
use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Actions\UpdateTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Filament\RelationManagers\TransactionDocumentsRelationManager;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
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

/**
 * Where the objects of the given documents live, read as system before they are deleted.
 *
 * @param  list<Document>  $documents
 * @return list<string>
 */
function receiptObjectPaths(array $documents): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => Media::query()
            ->whereIn('model_id', array_map(static fn (Document $d): string => $d->id, $documents))
            ->get()
            ->map(static fn (Media $media): string => $media->getPathRelativeToRoot())
            ->all(),
    );
}

describe('receipts follow the client and project of their transaction', function (): void {
    it('moves both receipts to the new client and no project when the transaction changes', function (): void {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $transaction = receiptTransaction($clientA, receiptProject($clientA));
        attachReceipts($transaction, 2);

        app(UpdateTransaction::class)->handle($this->admin, $transaction, ['client_id' => $clientB->id, 'project_id' => null]);

        $documents = Document::query()->where('owner_id', $transaction->id)->get();

        expect($documents)->toHaveCount(2)
            ->and($documents->every(fn (Document $d): bool => $d->client_id === $clientB->id && $d->project_id === null))->toBeTrue();
    });

    it('moves the receipts to a new project of the same client and clears them when the client is removed', function (): void {
        $client = Client::factory()->create();
        $first = receiptProject($client, 'RCA');
        $second = receiptProject($client, 'RCB');
        $transaction = receiptTransaction($client, $first);
        attachReceipts($transaction);

        app(UpdateTransaction::class)->handle($this->admin, $transaction, ['project_id' => $second->id]);

        expect(Document::query()->sole())->toMatchArray(['client_id' => $client->id, 'project_id' => $second->id]);

        app(UpdateTransaction::class)->handle($this->admin, $transaction, ['client_id' => null, 'project_id' => null]);

        expect(Document::query()->sole())->toMatchArray(['client_id' => null, 'project_id' => null]);
    });

    it('leaves no document that disagrees with what the resolver gives for its owner', function (): void {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $moved = receiptTransaction($clientA, receiptProject($clientA));
        $still = receiptTransaction($clientB);
        attachReceipts($moved, 2);
        attachReceipts($still);

        app(UpdateTransaction::class)->handle($this->admin, $moved, ['client_id' => $clientB->id, 'project_id' => null]);
        app(UpdateTransaction::class)->handle($this->admin, $still, ['description' => 'Example note only']);

        $resolver = app(DocumentOwnerResolver::class);
        $disagreeing = Document::query()->with('owner')->get()->filter(static function (Document $document) use ($resolver): bool {
            $resolved = $resolver->resolve($document->owner);

            return $document->client_id !== $resolved['client_id'] || $document->project_id !== $resolved['project_id'];
        });

        expect(Document::query()->count())->toBe(3)
            ->and($disagreeing)->toHaveCount(0);
    });

    it('does not touch the receipts of other transactions', function (): void {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $edited = receiptTransaction($client);
        $untouched = receiptTransaction($client);
        attachReceipts($edited);
        attachReceipts($untouched);

        app(UpdateTransaction::class)->handle($this->admin, $edited, ['client_id' => $other->id, 'project_id' => null]);

        expect(Document::query()->where('owner_id', $untouched->id)->sole()->client_id)->toBe($client->id);
    });
});

describe('deleting a transaction deletes its receipts', function (): void {
    it('removes the transaction, both receipt rows, both media rows and both objects, and names the count in the confirmation', function (): void {
        $transaction = receiptTransaction();
        $documents = attachReceipts($transaction, 2);
        $paths = receiptObjectPaths($documents);

        expect($paths)->toHaveCount(2)
            ->and(Storage::disk('local')->exists($paths[0]))->toBeTrue()
            ->and(Storage::disk('local')->exists($paths[1]))->toBeTrue();

        $page = Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->mountAction(DeleteAction::class)
            ->assertActionMounted(DeleteAction::class);

        expect($page->instance()->getMountedAction()?->getModalDescription())->toContain('2 doklady')
            ->and(Document::query()->count())->toBe(2);

        $page->callMountedAction()->assertRedirect(TransactionResource::getUrl('index'));

        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeFalse()
            ->and(Document::query()->count())->toBe(0)
            ->and(receiptObjectPaths($documents))->toBe([])
            ->and(Storage::disk('local')->exists($paths[0]))->toBeFalse()
            ->and(Storage::disk('local')->exists($paths[1]))->toBeFalse();
    });

    it('keeps the plain confirmation for a transaction without receipts and deletes only its own receipts', function (): void {
        $plain = receiptTransaction();
        $other = receiptTransaction();
        $kept = attachReceipts($other);

        $page = Livewire::test(EditTransaction::class, ['record' => $plain->getKey()])->mountAction(DeleteAction::class);

        expect($page->instance()->getMountedAction()?->getModalDescription())->toBe(__('finance.transactions.actions.delete_description'));

        $page->callMountedAction();

        expect(Transaction::query()->whereKey($plain->getKey())->exists())->toBeFalse()
            ->and(Document::query()->whereKey($kept[0]->id)->exists())->toBeTrue();
    });

    it('keeps the transaction and the receipt that cannot be removed when an object stays, and reports the error', function (): void {
        $transaction = receiptTransaction();
        $documents = attachReceipts($transaction, 2);
        $paths = receiptObjectPaths($documents);
        $stuck = $paths[1];

        $real = Storage::disk('local');
        $failing = Mockery::mock($real)->makePartial();
        $failing->shouldReceive('delete')->andReturnUsing(static function (mixed $target) use ($real, $stuck): bool {
            if ($target === $stuck) {
                throw new RuntimeException('Fictional disk failure.');
            }

            return $real->delete($target);
        });
        Storage::set('local', $failing);

        expect(fn () => app(DeleteTransaction::class)->handle($this->admin, $transaction))->toThrow(RuntimeException::class);

        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeTrue()
            ->and(Storage::disk('local')->exists($stuck))->toBeTrue()
            ->and(Document::query()->whereKey($documents[1]->id)->exists())->toBeTrue();
    });

    it('tells the Admin the transaction was not deleted when an object cannot be removed', function (): void {
        $transaction = receiptTransaction();
        [$document] = attachReceipts($transaction);
        $stuck = receiptObjectPaths([$document])[0];

        $real = Storage::disk('local');
        $failing = Mockery::mock($real)->makePartial();
        $failing->shouldReceive('delete')->andReturnUsing(static function (mixed $target) use ($real, $stuck): bool {
            if ($target === $stuck) {
                throw new RuntimeException('Fictional disk failure.');
            }

            return $real->delete($target);
        });
        Storage::set('local', $failing);
        Exceptions::fake();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified(__('finance.transactions.notifications.delete_failed'))
            ->assertNoRedirect();

        Exceptions::assertReported(RuntimeException::class);

        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeTrue();
    });
});

describe('the list shows the receipts', function (): void {
    it('shows the number of receipts per transaction', function (): void {
        $none = receiptTransaction();
        $two = receiptTransaction();
        attachReceipts($two, 2);

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$none, $two])
            ->assertTableColumnStateSet('documents_count', 0, $none)
            ->assertTableColumnStateSet('documents_count', 2, $two);
    });

    it('filters the transactions without a receipt and those with one', function (): void {
        $none = receiptTransaction();
        $with = receiptTransaction();
        attachReceipts($with);

        Livewire::test(ListTransactions::class)
            ->filterTable('without_receipt', true)
            ->assertCanSeeTableRecords([$none])
            ->assertCanNotSeeTableRecords([$with])
            ->filterTable('without_receipt', false)
            ->assertCanSeeTableRecords([$with])
            ->assertCanNotSeeTableRecords([$none]);
    });
});

describe('a receipt is not a file of an archived owner', function (): void {
    it('stays in the default Admin listing after the client of its transaction is archived (D-21)', function (): void {
        $client = Client::factory()->create();
        $transaction = receiptTransaction($client, receiptProject($client));
        $documents = attachReceipts($transaction, 2);
        $ids = array_map(static fn (Document $d): string => $d->id, $documents);

        app(ArchiveClient::class)->handle($client);

        expect(Document::query()->withoutArchivedOwners()->whereIn('id', $ids)->count())->toBe(2)
            ->and(Document::query()->onlyArchivedOwners()->whereIn('id', $ids)->count())->toBe(0)
            ->and(receiptObjectPaths($documents))->toHaveCount(2);
    });
});
