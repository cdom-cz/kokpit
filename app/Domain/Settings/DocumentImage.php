<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use finfo;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * The logo and the signature image of the documents (D-24): hardened upload,
 * content-addressed storage on the private media disk, hash-verified reads.
 *
 * An upload is untrusted. Its bytes are read with a hard bound, the type is
 * detected from the content (finfo and getimagesizefromstring, never the client
 * name or MIME), the size and dimensions are capped, and the image is decoded and
 * re-encoded by GD as a PNG, so nothing but pixels the encoder wrote is stored
 * (T-10-05). The object name is `settings/document-<kind>-<sha256 of the PNG>.png`:
 * the same image maps to the same object and nothing user-controlled reaches the
 * name.
 *
 * An issued document references its images by path and SHA-256 (D-21, D-23), so a
 * stored object is never deleted here (there is no delete method) and dataUri()
 * returns bytes only when they still match the hash, which makes a regenerated PDF
 * either equivalent to the issued one or a failure, never a different picture
 * (T-10-81). There is no public URL; the PDF embeds a data URI (T-10-07).
 */
final class DocumentImage
{
    public const int MAX_BYTES = 1048576;

    public const int MAX_DIMENSION = 4000;

    private const string MISMATCH = 'document image mismatch';

    /** @var list<string> */
    private const array KINDS = ['logo', 'signature'];

    /**
     * The regular expression (with delimiters) that a stored path of a kind must match.
     */
    public static function pathPattern(string $kind): string
    {
        self::assertKind($kind);

        return '#^settings/document-'.$kind.'-[0-9a-f]{64}\.png$#D';
    }

    /**
     * Checks, re-encodes and stores an upload and returns its path.
     *
     * @param  string  $kind  `logo` or `signature`
     *
     * @throws ValidationException under the key `<kind>_upload` for every refusal
     * @throws InvalidArgumentException for an unknown kind
     */
    public function store(UploadedFile $file, string $kind): string
    {
        self::assertKind($kind);

        $png = $this->reencode($this->read($file, $kind), $kind);
        $path = 'settings/document-'.$kind.'-'.hash('sha256', $png).'.png';
        $disk = $this->disk();

        // Content-addressed: an existing object with the right bytes is kept; one that was altered is restored.
        if (! $disk->exists($path) || hash('sha256', (string) $disk->get($path)) !== hash('sha256', $png)) {
            $disk->put($path, $png);
        }

        return $path;
    }

    /**
     * The path and SHA-256 of a stored object, for the issue snapshot. Null for no image.
     *
     * @return array{path: string, sha256: string}|null
     *
     * @throws RuntimeException when the object is missing or unreadable
     */
    public function reference(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }

        $bytes = $this->bytes($path);

        if ($bytes === null) {
            throw new RuntimeException('The stored document image cannot be read.');
        }

        return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
    }

    /**
     * The image as a data URI, only when the stored bytes still hash to `$sha256`.
     *
     * @throws UnexpectedValueException with the fixed message "document image mismatch" when the object is
     *                                  missing, is not a document image path, or differs from the hash
     */
    public function dataUri(string $path, string $sha256): string
    {
        $bytes = $this->bytes($path);

        if ($bytes === null || ! hash_equals(strtolower($sha256), hash('sha256', $bytes))) {
            throw new UnexpectedValueException(self::MISMATCH);
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    /**
     * The stored image as bytes for the one reader that embeds them: the e-mail layout puts the logo into the message
     * as an inline part (plan 13-04). There is still no public URL; the mail carries the bytes, as the PDF carries a
     * data URI. Null for a path that is not a document image path (a foreign path never reaches the disk) and for an
     * object that is gone.
     */
    public function contents(string $path): ?string
    {
        return $this->bytes($path);
    }

    /**
     * The bytes of a stored object, or null when the path is not a document image path or the object is gone.
     */
    private function bytes(string $path): ?string
    {
        if (preg_match(self::pathPattern('logo'), $path) !== 1 && preg_match(self::pathPattern('signature'), $path) !== 1) {
            return null;
        }

        try {
            $disk = $this->disk();

            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * Reads the upload with a bound of one byte past the limit, so an oversized object is never read in full.
     *
     * @throws ValidationException
     */
    private function read(UploadedFile $file, string $kind): string
    {
        $stream = null;

        try {
            $realPath = $file->getRealPath();
            $stream = $file instanceof TemporaryUploadedFile
                ? $file->readStream()
                : ($realPath === false ? false : @fopen($realPath, 'rb'));

            if (! is_resource($stream)) {
                throw $this->refusal($kind);
            }

            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw $this->refusal($kind);
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw $this->refusal($kind);
        }

        return $bytes;
    }

    /**
     * Detects the type from the content, checks the dimensions and re-encodes through GD as a PNG.
     *
     * @throws ValidationException
     */
    private function reencode(string $bytes, string $kind): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = @getimagesizefromstring($bytes);

        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || $info === false) {
            throw $this->refusal($kind);
        }

        // The two detections must agree: a polyglot that finfo calls one type and the header another is refused.
        if ($info[2] !== ($mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG)) {
            throw $this->refusal($kind);
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw $this->refusal($kind);
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw $this->refusal($kind);
        }

        try {
            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $written = imagepng($image, null, 6);
            $png = (string) ob_get_clean();
        } finally {
            unset($image);
        }

        if (! $written || $png === '') {
            throw $this->refusal($kind);
        }

        return $png;
    }

    private function refusal(string $kind): ValidationException
    {
        return ValidationException::withMessages([
            $kind.'_upload' => [__('kokpit.settings.supplier.image_invalid')],
        ]);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('media-library.disk_name'));
    }

    private static function assertKind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown document image kind.');
        }
    }
}
