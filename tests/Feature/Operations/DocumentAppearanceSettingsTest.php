<?php

declare(strict_types=1);

use App\Domain\Invoicing\Pdf\DocumentPalette;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Models\SettingsProperty;
use App\Filament\Pages\SettingsPage;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * Document appearance settings (D-24): the accent colour, the footer and the
 * two images are typed settings validated at the data layer and edited on the
 * one settings page.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @return array<string, string|null>
 */
function appearanceSupplierState(): array
{
    return [
        'company_name' => 'Example s.r.o.',
        'street' => 'Sample Street 1',
        'city' => 'Sampletown',
        'postal_code' => '10000',
        'country' => 'CZ',
        'company_id' => '12345678',
        'vat_id' => null,
        'email' => exampleEmail(),
        'phone' => null,
        'website' => null,
        'registration_note' => null,
    ];
}

/**
 * @param  array<string, mixed>  $invoicing
 * @return array<string, array<string, mixed>>
 */
function appearanceState(array $invoicing): array
{
    return [
        'supplier' => appearanceSupplierState(),
        'invoicing' => ['vat_mode' => 'non_payer', 'payment_due_days' => 14, ...$invoicing],
    ];
}

/**
 * @return array<string, string>
 */
function storedInvoicing(): array
{
    return SettingsProperty::query()->where('group', 'invoicing')->pluck('payload', 'name')->all();
}

it('starts with the default accent colour and no footer after migrate', function (): void {
    $this->actingAs(Canary::admin());

    $invoicing = app(InvoicingSettings::class);

    expect($invoicing->document_accent_color)->toBe('#ff6f00')
        ->and($invoicing->document_accent_color)->toBe(DocumentPalette::DEFAULT_ACCENT)
        ->and($invoicing->document_footer)->toBeNull();
});

it('saves the accent colour on the page and shows it after a reload', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->assertSet('data.invoicing.document_accent_color', '#ff6f00')
        ->fillForm(appearanceState(['document_accent_color' => '#1d4ed8']))
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('kokpit.settings.saved'));

    app()->forgetScopedInstances();

    expect(app(InvoicingSettings::class)->document_accent_color)->toBe('#1d4ed8');

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.invoicing.document_accent_color', '#1d4ed8');
});

it('stores an accent typed in upper case in lower case', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState([]))
        ->set('data.invoicing.document_accent_color', '#1D4ED8')
        ->assertSet('data.invoicing.document_accent_color', '#1d4ed8')
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(InvoicingSettings::class)->document_accent_color)->toBe('#1d4ed8');
});

it('normalises the accent from form state to lower case before the data layer checks it', function (): void {
    $this->actingAs(Canary::admin());

    $invoicing = app(InvoicingSettings::class)->fillFromFormState(['document_accent_color' => ' #1D4ED8 ']);

    expect($invoicing->document_accent_color)->toBe('#1d4ed8');
});

it('refuses an accent that is not #rrggbb with the Czech reason under the field and changes nothing', function (string $typed): void {
    $this->actingAs(Canary::admin());
    $before = storedInvoicing();

    $page = Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState(['document_accent_color' => $typed]))
        ->call('save')
        ->assertHasFormErrors(['invoicing.document_accent_color']);

    expect($page->errors()->first('data.invoicing.document_accent_color'))->toBe('Zadejte barvu ve tvaru #rrggbb.')
        ->and(storedInvoicing())->toBe($before);
})->with(['#12345', 'red', '', '#12345g', '#1234567', 'ff6f00', '#ff6f00; background:url(x)']);

it('refuses a bad accent at the data layer when the class is saved outside the form', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedInvoicing();

    $invoicing = app(InvoicingSettings::class);
    $invoicing->document_accent_color = '#FF6F00';

    expect(fn () => $invoicing->save())->toThrow(ValidationException::class)
        ->and(storedInvoicing())->toBe($before);
});

it('derives the on-accent text of the default accent as the ink colour', function (): void {
    $palette = DocumentPalette::fromAccent('#ff6f00');

    expect($palette->onAccent)->toBe($palette->ink);
});

it('refuses the settings page to a Partner, including the appearance fields', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]))
        ->get('/admin/settings')
        ->assertForbidden();
});

/**
 * A generated image as an upload, so the type is detected from the content.
 * The pixels are random, so no two calls give the same bytes unless a seed is
 * given.
 */
function appearanceImage(string $type = 'png', int $width = 200, int $height = 50, ?int $seed = null, bool $noise = false, string $name = 'brand'): UploadedFile
{
    mt_srand($seed ?? random_int(1, PHP_INT_MAX));

    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));

    if ($noise) {
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
    }

    ob_start();
    match ($type) {
        'jpeg' => imagejpeg($image, null, 90),
        default => imagepng($image),
    };
    $bytes = (string) ob_get_clean();

    return appearanceFile($bytes, $name.'.'.($type === 'jpeg' ? 'jpg' : 'png'));
}

/**
 * A Livewire-compatible fake upload with the given bytes; finfo reads the
 * content, not the name.
 */
function appearanceFile(string $content, string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function appearanceStoredCount(): int
{
    return count(Storage::disk('local')->allFiles());
}

it('stores a PNG logo re-encoded under a content-addressed name on the private media disk', function (): void {
    DocumentFixtures::fakeDisk();

    $path = app(DocumentImage::class)->store(appearanceImage('png', 200, 50), 'logo');

    expect($path)->toMatch('#^settings/document-logo-[0-9a-f]{64}\.png$#');

    $bytes = Storage::disk('local')->get($path);

    expect($bytes)->not->toBeNull()
        ->and($path)->toBe('settings/document-logo-'.hash('sha256', (string) $bytes).'.png')
        ->and(getimagesizefromstring((string) $bytes)[2])->toBe(IMAGETYPE_PNG)
        ->and(appearanceStoredCount())->toBe(1);
});

it('stores a JPEG as PNG and hands out a verified data URI and a reference', function (): void {
    DocumentFixtures::fakeDisk();
    $images = app(DocumentImage::class);

    $path = $images->store(appearanceImage('jpeg', 120, 60), 'signature');
    $reference = $images->reference($path);

    expect($path)->toMatch('#^settings/document-signature-[0-9a-f]{64}\.png$#')
        ->and($reference)->toBe(['path' => $path, 'sha256' => hash('sha256', (string) Storage::disk('local')->get($path))])
        ->and($images->dataUri($path, $reference['sha256']))->toStartWith('data:image/png;base64,')
        ->and($images->reference(null))->toBeNull();
});

it('maps the same image uploaded twice to the same path and one object', function (): void {
    DocumentFixtures::fakeDisk();
    $images = app(DocumentImage::class);
    $source = appearanceImage('png', 80, 40, seed: 7);
    $copy = appearanceFile((string) file_get_contents((string) $source->getRealPath()), 'again.png');

    expect($images->store($copy, 'logo'))->toBe($images->store($source, 'logo'))
        ->and(appearanceStoredCount())->toBe(1);
});

it('refuses a file that is not a PNG or JPEG image, is too large or too wide, and stores nothing', function (string $case): void {
    DocumentFixtures::fakeDisk();

    $upload = match ($case) {
        'svg' => appearanceFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'brand.svg'),
        'text renamed to jpg' => appearanceFile('<?php echo 1;', 'brand.jpg'),
        'gif' => appearanceFile(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true) ?: '', 'brand.png'),
        'two megabytes' => appearanceImage('png', 800, 800, seed: 3, noise: true),
        'too wide' => appearanceImage('png', 5000, 10),
        'empty' => appearanceFile('', 'brand.png'),
    };

    try {
        app(DocumentImage::class)->store($upload, 'logo');
        $this->fail('The file was accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['logo_upload'])
            ->and($exception->errors()['logo_upload'][0])->toContain('PNG')->toContain('JPEG')->toContain('1 MB');
    }

    expect(appearanceStoredCount())->toBe(0);
})->with(['svg', 'text renamed to jpg', 'gif', 'two megabytes', 'too wide', 'empty']);

it('refuses an unknown image kind', function (): void {
    DocumentFixtures::fakeDisk();

    expect(fn () => app(DocumentImage::class)->store(appearanceImage(), 'banner'))->toThrow(InvalidArgumentException::class);
});

it('refuses a data URI for an object that is missing, overwritten or hashed differently', function (): void {
    DocumentFixtures::fakeDisk();
    $images = app(DocumentImage::class);
    $path = $images->store(appearanceImage(), 'logo');
    $sha256 = $images->reference($path)['sha256'] ?? '';

    expect(fn () => $images->dataUri($path, str_repeat('0', 64)))->toThrow(UnexpectedValueException::class, 'document image mismatch');

    Storage::disk('local')->put($path, 'other bytes');
    expect(fn () => $images->dataUri($path, $sha256))->toThrow(UnexpectedValueException::class, 'document image mismatch');

    Storage::disk('local')->delete($path);
    expect(fn () => $images->dataUri($path, $sha256))->toThrow(UnexpectedValueException::class, 'document image mismatch')
        ->and(fn () => $images->reference($path))->toThrow(RuntimeException::class);
});

it('refuses to read a path that is not a stored document image', function (): void {
    DocumentFixtures::fakeDisk();
    Storage::disk('local')->put('secret.txt', 'private');

    expect(fn () => app(DocumentImage::class)->dataUri('secret.txt', hash('sha256', 'private')))
        ->toThrow(UnexpectedValueException::class, 'document image mismatch');
});

it('repairs a stored object whose bytes no longer match when the same image is uploaded again', function (): void {
    DocumentFixtures::fakeDisk();
    $images = app(DocumentImage::class);
    $source = appearanceImage('png', 60, 30, seed: 11);
    $path = $images->store($source, 'logo');
    $sha256 = $images->reference($path)['sha256'] ?? '';

    Storage::disk('local')->put($path, 'tampered');

    expect($images->store(appearanceFile((string) file_get_contents((string) $source->getRealPath()), 'brand.png'), 'logo'))->toBe($path)
        ->and($images->dataUri($path, $sha256))->toStartWith('data:image/png;base64,');
});

it('uploads a logo and a signature on the page and keeps the stored objects when they are replaced or cleared', function (): void {
    DocumentFixtures::fakeDisk();
    $this->actingAs(Canary::admin());
    $images = app(DocumentImage::class);

    $page = Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState([]))
        ->set('data.supplier.logo_upload', appearanceImage('png', 200, 50))
        ->set('data.supplier.signature_upload', appearanceImage('jpeg', 100, 40))
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $supplier = app(SupplierSettings::class);
    $firstLogo = (string) $supplier->logo_path;
    $firstSignature = (string) $supplier->signature_path;
    $firstLogoHash = $images->reference($firstLogo)['sha256'] ?? '';

    expect($firstLogo)->toMatch('#^settings/document-logo-[0-9a-f]{64}\.png$#')
        ->and($firstSignature)->toMatch('#^settings/document-signature-[0-9a-f]{64}\.png$#')
        ->and(appearanceStoredCount())->toBe(2);

    // Replace the logo, clear the signature.
    $page->set('data.supplier.logo_upload', appearanceImage('png', 160, 40))
        ->set('data.supplier.signature_remove', true)
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $supplier = app(SupplierSettings::class);

    expect($supplier->logo_path)->not->toBeNull()->not->toBe($firstLogo)
        ->and($supplier->signature_path)->toBeNull()
        ->and(appearanceStoredCount())->toBe(3)
        ->and($images->dataUri($firstLogo, $firstLogoHash))->toStartWith('data:image/png;base64,')
        ->and(Storage::disk('local')->exists($firstSignature))->toBeTrue();
});

it('shows the stored logo as a preview and starts the upload field empty after a save', function (): void {
    DocumentFixtures::fakeDisk();
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState([]))
        ->assertDontSee('data:image/png;base64')
        ->set('data.supplier.logo_upload', appearanceImage('png', 200, 50))
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.supplier.logo_upload', [])
        ->assertSet('data.supplier.logo_remove', false)
        ->assertSee('data:image/png;base64')
        ->assertSee(__('kokpit.settings.supplier.logo_remove'));
});

it('refuses an invalid logo with a field error and changes nothing', function (): void {
    DocumentFixtures::fakeDisk();
    $this->actingAs(Canary::admin());

    $page = Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState([]))
        ->set('data.supplier.logo_upload', appearanceFile('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'brand.png'))
        ->call('save');

    app()->forgetScopedInstances();

    expect($page->errors()->has('data.supplier.logo_upload'))->toBeTrue()
        ->and(app(SupplierSettings::class)->logo_path)->toBeNull()
        ->and(appearanceStoredCount())->toBe(0);
});

it('ignores a logo path sent in the form state and refuses a bad path at the data layer', function (): void {
    DocumentFixtures::fakeDisk();
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState([]))
        ->set('data.supplier.logo_path', '../x')
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    expect(app(SupplierSettings::class)->logo_path)->toBeNull();

    foreach (['../x', 'settings/../x.png', 'settings/document-logo-abc.png', 'other/document-logo-'.str_repeat('a', 64).'.png', 'settings/document-signature-'.str_repeat('a', 64).'.png'] as $bad) {
        $supplier = app(SupplierSettings::class);
        $supplier->company_name = 'Example s.r.o.';
        $supplier->logo_path = $bad;

        expect(fn () => $supplier->save())->toThrow(ValidationException::class);
    }

    $supplier = app(SupplierSettings::class);
    $supplier->company_name = 'Example s.r.o.';
    $supplier->logo_path = 'settings/document-logo-'.str_repeat('a', 64).'.png';
    $supplier->signature_path = 'settings/document-signature-'.str_repeat('b', 64).'.png';

    expect($supplier->save())->toBe($supplier);
});

it('stores a footer of 300 characters and refuses 301', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState(['document_footer' => str_repeat('ž', 300)]))
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    expect(app(InvoicingSettings::class)->document_footer)->toBe(str_repeat('ž', 300));

    $before = storedInvoicing();

    Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState(['document_footer' => str_repeat('ž', 301)]))
        ->call('save')
        ->assertHasFormErrors(['invoicing.document_footer']);

    expect(storedInvoicing())->toBe($before);

    $invoicing = app(InvoicingSettings::class);
    $invoicing->document_footer = str_repeat('x', 301);

    expect(fn () => $invoicing->save())->toThrow(ValidationException::class);
});

it('keeps an empty footer as null', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState(['document_footer' => '']))
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(InvoicingSettings::class)->document_footer)->toBeNull();
});
