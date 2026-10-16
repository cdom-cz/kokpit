<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

use App\Domain\Documents\Models\Document;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * The file-type groups of the central listing's filter (D-07). A file belongs to exactly one group:
 * images and PDF by the MIME type detected from the bytes at upload, the office groups and archives by
 * the stored extension, everything else to "other". Order matters: an image or a PDF never lands in an
 * extension group, whatever its name says.
 *
 * {@see self::of()} names the group of one file, {@see self::constrain()} is the same rule as a query, so the
 * filter and the label can never disagree.
 */
enum DocumentTypeGroup: string implements HasLabel
{
    case Image = 'image';
    case Pdf = 'pdf';
    case Text = 'text';
    case Spreadsheet = 'spreadsheet';
    case Presentation = 'presentation';
    case Archive = 'archive';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('enums.document_type_group.'.$this->value);
    }

    /**
     * The group of a stored file.
     */
    public static function of(Document $document): self
    {
        if (str_starts_with($document->mime_type, 'image/')) {
            return self::Image;
        }

        if ($document->mime_type === 'application/pdf') {
            return self::Pdf;
        }

        foreach (self::byExtension() as $group) {
            if ($document->extension !== null && in_array($document->extension, $group->extensions(), true)) {
                return $group;
            }
        }

        return self::Other;
    }

    /**
     * Narrows a documents query to the files of this group.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function constrain(Builder $query): Builder
    {
        $mime = $query->qualifyColumn('mime_type');
        $extension = $query->qualifyColumn('extension');

        return match ($this) {
            self::Image => $query->where($mime, 'like', 'image/%'),
            self::Pdf => $query->where($mime, 'application/pdf'),
            self::Text, self::Spreadsheet, self::Presentation, self::Archive => $this->withoutImageOrPdf($query)
                ->whereIn($extension, $this->extensions()),
            self::Other => $this->withoutImageOrPdf($query)
                ->where(static fn (Builder $other): Builder => $other
                    ->whereNull($extension)
                    ->orWhereNotIn($extension, self::allExtensions())),
        };
    }

    /**
     * The stored extensions (lower case, no dot) that put a file in this group; empty for the groups that go
     * by the MIME type or by elimination.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Text => ['doc', 'docx', 'odt', 'rtf', 'txt', 'md'],
            self::Spreadsheet => ['xls', 'xlsx', 'ods', 'csv'],
            self::Presentation => ['ppt', 'pptx', 'odp'],
            self::Archive => ['zip', '7z', 'rar', 'tar', 'gz'],
            self::Image, self::Pdf, self::Other => [],
        };
    }

    /**
     * The groups decided by extension, in the order they are tried.
     *
     * @return list<self>
     */
    private static function byExtension(): array
    {
        return [self::Text, self::Spreadsheet, self::Presentation, self::Archive];
    }

    /**
     * @return list<string>
     */
    private static function allExtensions(): array
    {
        return array_merge(...array_map(static fn (self $group): array => $group->extensions(), self::byExtension()));
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private function withoutImageOrPdf(Builder $query): Builder
    {
        return $query->where(static fn (Builder $files): Builder => $files
            ->where($query->qualifyColumn('mime_type'), 'not like', 'image/%')
            ->where($query->qualifyColumn('mime_type'), '<>', 'application/pdf'));
    }
}
