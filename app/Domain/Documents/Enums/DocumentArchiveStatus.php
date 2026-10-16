<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a document archive stands: waiting for the queue, finished, or failed (D-08).
 *
 * The values equal the `document_archives_status_check` constraint of the table.
 */
enum DocumentArchiveStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('enums.document_archive_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
