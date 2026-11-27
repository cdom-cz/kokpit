<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\StoreArticleImage;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Images\ImageRefusal;
use App\Domain\Knowledge\Images\ImageRefused;
use League\Flysystem\FilesystemException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

/**
 * The server side of the image upload of the editor, on the create and the edit screen (IMG-01, IMG-02, D-01, D-02).
 *
 * The editor script uploads the file through a Livewire temporary upload into `$imageUpload` (the private bucket path,
 * with its one-day expiry and the 10 MB rule) and then calls {@see storeImageUpload()}. The method hands the file to
 * {@see StoreArticleImage}, always deletes the temporary original afterwards, and answers with the reference to insert
 * or with the Czech refusal. Filament's own attachment flow stays off (`fileAttachments(false)`).
 *
 * The original is never kept: it is deleted in `finally`, on success and on refusal alike. A crash between the upload
 * and that cleanup leaves the original in `livewire-tmp` until the bucket's one-day expiry (research Pitfall 8,
 * accepted residual).
 *
 * The Partner refusal comes from the mount of the page (403 before the form is built) and from the gate of the Action,
 * which a forged request to this method still meets.
 *
 * The method is deliberately not renderless: its response re-renders the form, so the secret notice of the editor
 * appears and stays across later morphs (an element unhidden by a script would be reset by the next morph).
 */
trait HandlesImageUploads
{
    /**
     * The temporary upload the editor script has just finished; read and cleared by {@see storeImageUpload()}.
     */
    public mixed $imageUpload = null;

    /**
     * Set by the first successful upload on this screen; a refused upload does not set it (D-05).
     */
    public bool $imageNoticeVisible = false;

    public function showsImageNotice(): bool
    {
        return $this->imageNoticeVisible;
    }

    /**
     * Stores the temporary upload as a pending image and answers for the editor script.
     *
     * @return array{ok: true, id: string, reference: string, alt: string, width: int, height: int}|array{ok: false, title: string, body: string}
     */
    public function storeImageUpload(): array
    {
        $file = $this->imageUpload;
        $this->imageUpload = null;

        if (! $file instanceof TemporaryUploadedFile) {
            return $this->imageUploadRefusal(ImageRefusal::Failed);
        }

        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            $stored = app(StoreArticleImage::class)->handle($actor, $file);
        } catch (ImageRefused $refused) {
            return $this->imageUploadRefusal($refused->refusal);
        } catch (ArticleWriteFailed|FilesystemException $failure) {
            // The message of either is content-free (a SQLSTATE, or an object key without user text).
            report($failure);

            return $this->imageUploadRefusal(ImageRefusal::Failed);
        } finally {
            $this->deleteTemporaryImageUpload($file);
        }

        $this->imageNoticeVisible = true;

        return [
            'ok' => true,
            'id' => $stored->id,
            'reference' => $stored->reference,
            'alt' => $stored->altText,
            'width' => $stored->width,
            'height' => $stored->height,
        ];
    }

    /**
     * @return array{ok: false, title: string, body: string}
     */
    private function imageUploadRefusal(ImageRefusal $refusal): array
    {
        return [
            'ok' => false,
            'title' => trans()->string('knowledge.images.errors.title'),
            'body' => trans()->string($refusal->messageKey()),
        ];
    }

    /**
     * Best effort, reported by class only: the message of a storage exception can name the object.
     */
    private function deleteTemporaryImageUpload(TemporaryUploadedFile $file): void
    {
        try {
            $file->delete();
        } catch (Throwable $e) {
            report(new RuntimeException('A temporary image upload could not be deleted ('.$e::class.').'));
        }
    }
}
