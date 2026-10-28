<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\QueuePaymentConfirmation;
use App\Domain\Invoicing\Models\Invoice;

/**
 * The domain gate of the per-client switch "send documents by e-mail" (Phase 16, D-06, D-07): may this client
 * receive a document e-mail? One implementation that every document path asks, so the answer is the same in the
 * Action that queues, the job that sends and the screens that offer the send.
 *
 * Only the document e-mail and the payment confirmation paths ask it (D-01). Invitations, password resets and task
 * notifications of the client's users never do: the switch does not touch them.
 *
 * The answer comes from a fresh read of `clients.document_emails_enabled` on every call, never from a cached
 * relation: the Admin may switch the client off inside the grace window of a queued confirmation, and the job must
 * see that. `allows()` is the only method that takes a model, and only one that was just read from the database
 * with the column (an eager-loaded list relation, or the client a payment transaction has just read).
 *
 * Fail-closed: a row that cannot be read answers false, "do not send". That is the outcome
 * {@see QueuePaymentConfirmation} already gives (a skipped row) when it cannot read
 * the client; the restrict foreign key of `invoices.client_id` makes a missing row impossible in practice.
 *
 * The class never enters the system context itself: its callers are Admin requests and `KokpitJob` jobs, which
 * already run in it. An archived client is read as well (`withTrashed()`), as its documents still exist.
 */
final readonly class ClientDocumentEmails
{
    /**
     * Whether the client with this id may receive document e-mails, read from the database now.
     */
    public static function enabledFor(string $clientId): bool
    {
        $value = Client::query()->withTrashed()->whereKey($clientId)->value('document_emails_enabled');

        return $value === true;
    }

    /**
     * Whether the client of this invoice may receive document e-mails; the client id is read fresh as well.
     * An unknown invoice answers false.
     */
    public static function enabledForInvoice(string $invoiceId): bool
    {
        $clientId = Invoice::query()->whereKey($invoiceId)->value('client_id');

        return is_string($clientId) && self::enabledFor($clientId);
    }

    /**
     * Whether a client model that was just read from the database with the column may receive document e-mails.
     * A missing client answers false.
     */
    public static function allows(?Client $client): bool
    {
        return $client !== null && $client->document_emails_enabled === true;
    }
}
