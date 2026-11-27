<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Concerns;

/**
 * What the shared article form asks of a page that takes image uploads (D-05): whether the secret notice of the editor
 * is shown. The create screen and the edit screen implement it through {@see HandlesImageUploads}.
 */
interface ImageUploadForm
{
    /**
     * Whether an image was stored on this screen, so the notice that an image is not covered by the secret scan is
     * shown (it stays for the rest of the visit).
     */
    public function showsImageNotice(): bool;
}
