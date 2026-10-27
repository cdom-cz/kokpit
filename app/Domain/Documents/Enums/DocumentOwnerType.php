<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a file can hang on. The values are the morph aliases stored in `documents.owner_type`
 * (`invoice`, the PDF of an issued billing document, was added in Phase 10; `cash_receipt`, the PDF of a cash
 * receipt, in Phase 15).
 *
 * Only a project, a task and a task comment can be shared with a Partner (D-03); the database
 * CHECK `documents_visible_owner_check` mirrors that list.
 */
enum DocumentOwnerType: string implements HasLabel
{
    case Client = 'client';
    case Project = 'project';
    case Task = 'task';
    case TaskComment = 'task_comment';
    case Transaction = 'transaction';
    case Invoice = 'invoice';
    case CashReceipt = 'cash_receipt';

    public function getLabel(): string
    {
        return __('enums.document_owner_type.'.$this->value);
    }

    public function isShareable(): bool
    {
        return match ($this) {
            self::Project, self::Task, self::TaskComment => true,
            self::Client, self::Transaction, self::Invoice, self::CashReceipt => false,
        };
    }
}
