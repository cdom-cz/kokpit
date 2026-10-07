<?php

declare(strict_types=1);

use Filament\Auth\Pages\Login;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

it('runs in Czech with an English fallback', function (): void {
    expect(app()->getLocale())->toBe('cs')
        ->and(app()->getFallbackLocale())->toBe('en');
});

it('defaults to cs, en and cs_CZ when the environment omits the locale variables', function (): void {
    // A fresh PHP process with the three variables removed evaluates config/app.php on its own,
    // so neither phpunit.xml nor a developer's local .env can mask a wrong default.
    $script = 'require "vendor/autoload.php"; $c = require "config/app.php"; echo json_encode([$c["locale"], $c["fallback_locale"], $c["faker_locale"], $c["timezone"]]);';
    $process = new Process([PHP_BINARY, '-r', $script], base_path());
    $process->run(null, ['APP_LOCALE' => false, 'APP_FALLBACK_LOCALE' => false, 'APP_FAKER_LOCALE' => false]);

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode($process->getOutput(), true))->toBe(['cs', 'en', 'cs_CZ', 'UTC']);
});

it('renders the panel login page in Czech', function (): void {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('Přihlášení');
});

it('renders validation messages in Czech', function (): void {
    $message = Validator::make(['email' => ''], ['email' => 'required'])->errors()->first('email');

    expect($message)->toContain('musí být vyplněn');
});

it('stores in UTC and displays in Europe/Prague', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(FilamentTimezone::get())->toBe('Europe/Prague');
});

it('shows the Czech required-field message when the login form is submitted without an e-mail', function (): void {
    $component = Livewire::test(Login::class)
        ->fillForm(['email' => '', 'password' => ''])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => 'required']);

    $message = (string) collect($component->errors()->get('data.email'))->first();

    expect($message)->toContain('musí být vyplněn')
        ->and($message)->not->toContain('required')
        ->and($message)->not->toContain('validation.');
});
