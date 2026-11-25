<?php

declare(strict_types=1);

use App\Domain\Knowledge\Guard\SecretRule;

/*
 * lang/cs/knowledge.php holds every Czech string of the knowledge screens and the secret guard under
 * English keys (UI-SPEC copywriting contract). This file pins that every rule has a label and that the
 * keys the later plans read exist with their agreed wording.
 */

it('gives every secret rule a Czech label different from its key', function (): void {
    foreach (SecretRule::cases() as $rule) {
        $key = 'knowledge.guard.rules.'.$rule->value;

        expect(__($key))->not->toBe($key)->and(__($key))->not->toBe('');
    }
});

it('composes a finding line from the rule label and the line number', function (): void {
    expect(__('knowledge.guard.finding', ['rule' => __('knowledge.guard.rules.github_token'), 'line' => 3]))
        ->toBe('Token GitHub — řádek 3')
        ->and(__('knowledge.guard.finding_without_line', ['rule' => __('knowledge.guard.rules.scan_failed')]))
        ->toBe('Kontrolu tajných údajů nešlo dokončit');
});

it('holds the strings of the rendered article', function (): void {
    $render = [
        'knowledge.render.callouts.note' => 'Poznámka',
        'knowledge.render.callouts.tip' => 'Tip',
        'knowledge.render.callouts.important' => 'Důležité',
        'knowledge.render.callouts.warning' => 'Varování',
        'knowledge.render.callouts.caution' => 'Pozor',
        'knowledge.render.code.copy' => 'Kopírovat kód',
        'knowledge.render.code.copied' => 'Zkopírováno',
        'knowledge.render.code.copy_failed' => 'Kopírování se nezdařilo',
        'knowledge.render.code.label' => 'Kód: :language',
        'knowledge.render.code.label_plain' => 'Kód',
        'knowledge.render.task.done' => 'Hotovo:',
        'knowledge.render.task.open' => 'Nehotovo:',
    ];

    foreach ($render as $key => $value) {
        expect(__($key, ['language' => ':language']))->toBe($value);
    }
});

it('holds the strings of the save refusals', function (): void {
    expect(__('knowledge.notifications.write_failed_title'))->toBe('Článek se nepodařilo uložit')
        ->and(__('knowledge.notifications.write_failed_body'))->toBe('Váš text zůstal v editoru, aby ho šlo zkopírovat. Zkuste článek uložit znovu.')
        ->and(__('knowledge.notifications.archived_meanwhile_title'))->toBe('Článek byl mezitím archivován')
        ->and(__('knowledge.notifications.archived_meanwhile_body'))->toBe('Váš text zůstal v editoru, aby ho šlo zkopírovat. Článek nejprve obnovte a změny pak uložte znovu.')
        ->and(__('knowledge.errors.nul_character'))->toBe('Text obsahuje neplatný znak (NUL). Odstraňte ho a uložte znovu.');
});

it('holds the strings of the editor, the actions and the notifications', function (): void {
    expect(__('knowledge.navigation_group'))->toBe('Znalosti')
        ->and(__('knowledge.articles.navigation_label'))->toBe('Obecné znalosti')
        ->and(__('knowledge.tabs.write'))->toBe('Psaní')
        ->and(__('knowledge.tabs.preview'))->toBe('Náhled')
        ->and(__('knowledge.actions.save_anyway'))->toBe('Uložit článek i přesto')
        ->and(__('knowledge.actions.load_current'))->toBe('Načíst aktuální verzi')
        ->and(__('knowledge.notifications.saved', ['version' => 4]))->toBe('Článek byl uložen jako verze 4')
        ->and(__('knowledge.notifications.unchanged'))->toBe('Nic se nezměnilo, nová verze nevznikla.')
        ->and(__('knowledge.guard.blocking_heading'))->toBe('Text obsahuje tajný údaj a nelze ho uložit')
        ->and(__('knowledge.errors.title_too_long', ['max' => 200]))->toBe('Název může mít nejvýše 200 znaků.');
});
