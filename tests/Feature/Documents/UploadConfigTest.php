<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\S3DoesntSupportMultipleFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Livewire\WithFileUploads;

/*
 * D-18: Livewire temporary uploads go to the private S3-compatible bucket, which every web container
 * shares. The suite runs that production setting (phpunit.xml pins the disk); under tests Livewire keeps
 * the bytes on its own faked disk and signs nothing real, so no test contacts the bucket. D-06: one
 * limit for every upload. D-10: no preview URL for any file. All names and contents are fictional.
 */
final class UploadConfigProbe extends Component
{
    use WithFileUploads;

    public $file;

    /** @var array<int, mixed> */
    public $files = [];

    public function render(): string
    {
        return '<div></div>';
    }
}

/** The four keys of the published config that differ from the Livewire defaults. */
const UPLOAD_CONFIG_CHANGED_KEYS = [
    'temporary_file_upload.disk',
    'temporary_file_upload.rules',
    'temporary_file_upload.middleware',
    'temporary_file_upload.preview_mimes',
];

it('holds temporary uploads on the bucket disk in the suite, as production does', function (): void {
    expect(config('livewire.temporary_file_upload.disk'))->toBe('s3')
        ->and(FileUploadConfiguration::isUsingS3())->toBeTrue();
});

it('takes the bucket branch when an upload starts', function (): void {
    $info = [['name' => 'note.txt', 'size' => 1024, 'type' => 'text/plain']];

    // The signed URL itself is a stub under tests: Livewire signs nothing real and contacts no bucket.
    Livewire::test(UploadConfigProbe::class)
        ->call('_startUpload', 'file', $info, false)
        ->assertDispatched('upload:generatedSignedUrlForS3')
        ->assertNotDispatched('upload:generatedSignedUrl');
});

it('finishes an upload with a temporary file whose bytes stay on the faked test disk', function (): void {
    $content = implode(' ', ['Fictional', 'upload', 'note']);
    $file = UploadedFile::fake()->createWithContent('note.txt', $content);

    $stored = Livewire::test(UploadConfigProbe::class)
        ->upload('file', [$file])
        ->get('file');

    expect($stored)->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and(FileUploadConfiguration::disk())->toBe('tmp-for-tests')
        ->and($stored->get())->toBe($content);
});

it('refuses a list of files on one property, so a form uploads one file at a time like the browser', function (): void {
    $first = UploadedFile::fake()->createWithContent('first.txt', 'Fictional first');
    $second = UploadedFile::fake()->createWithContent('second.txt', 'Fictional second');

    expect(fn () => Livewire::test(UploadConfigProbe::class)->upload('files', [$first, $second], isMultiple: true))
        ->toThrow(S3DoesntSupportMultipleFileUploads::class);
});

it('applies one size rule everywhere, equal to the limit of the media library', function (): void {
    $limit = config('kokpit.documents.max_size_kb');

    expect(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:'.$limit])
        ->and(FileUploadConfiguration::rules())->toBe(['required', 'file', 'max:'.$limit])
        ->and(config('media-library.max_file_size'))->toBe($limit * 1024);
});

it('offers no temporary preview URL for any file type', function (): void {
    expect(config('livewire.temporary_file_upload.preview_mimes'))->toBe([]);
});

it('keeps the upload route throttle', function (): void {
    expect(FileUploadConfiguration::middleware())->toBe('throttle:60,1');
});

it('changes nothing else of the published Livewire config', function (): void {
    $vendor = require base_path('vendor/livewire/livewire/config/livewire.php');
    $published = require config_path('livewire.php');

    // Arr::dot flattens the list values into numbered keys, so a changed key is removed by its prefix.
    $strip = static fn (array $config): array => array_filter(
        Arr::dot($config),
        static fn (string $key): bool => ! collect(UPLOAD_CONFIG_CHANGED_KEYS)->contains(
            static fn (string $changed): bool => $key === $changed || str_starts_with($key, $changed.'.'),
        ),
        ARRAY_FILTER_USE_KEY,
    );

    expect($strip($published))->toBe($strip($vendor));
});
