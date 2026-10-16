<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Helpers of the documents tests: a fake private media disk and fictional uploads.
 *
 * File names and contents are assembled from fragments when the test runs, so
 * no test file carries a complete name or text of a real document.
 */
final class DocumentFixtures
{
    /**
     * Points the media library at a fake local disk, like the canary harness does.
     */
    public static function fakeDisk(): void
    {
        config(['media-library.disk_name' => 'local']);
        Storage::fake('local');
    }

    /**
     * A real temporary file wrapped in an UploadedFile in test mode, so the MIME type is detected from
     * the content (finfo) exactly as for a real upload. The default content is plain text, which finfo
     * reports as text/plain.
     */
    public static function upload(?string $name = null, ?string $content = null): UploadedFile
    {
        $name ??= implode('-', ['fictional', 'note', Str::lower(Str::random(6))]).'.txt';
        $content ??= implode(' ', ['Fictional', 'file', 'content', Str::random(12)]);

        $path = tempnam(sys_get_temp_dir(), 'kokpit-doc-');

        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
