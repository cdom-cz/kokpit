<?php

declare(strict_types=1);

use App\Domain\Knowledge\Guard\SecretRule;
use Illuminate\Support\Arr;

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

/*
 * Phase 19 (folders, tags, links, version history, diff, restore and redaction): the table below is the copy
 * contract of plan 19-02 (UI-SPEC Copywriting Contract). A later plan reads a string by its key and never words
 * one of its own, so a drifting text fails here. The plural keys are written with the pipe of the plural syntax.
 */

dataset('phase 19 copy', [
    'folders.heading' => ['knowledge.folders.heading', 'Složky'],
    'folders.tree_label' => ['knowledge.folders.tree_label', 'Složky'],
    'folders.all' => ['knowledge.folders.all', 'Všechny články'],
    'folders.none' => ['knowledge.folders.none', 'Bez složky'],
    'folders.current_selection' => ['knowledge.folders.current_selection', 'Složky: :name'],
    'folders.new' => ['knowledge.folders.new', 'Nová složka'],
    'folders.create_submit' => ['knowledge.folders.create_submit', 'Vytvořit složku'],
    'folders.new_subfolder' => ['knowledge.folders.new_subfolder', 'Nová podsložka'],
    'folders.rename' => ['knowledge.folders.rename', 'Přejmenovat'],
    'folders.rename_heading' => ['knowledge.folders.rename_heading', 'Přejmenovat složku'],
    'folders.move' => ['knowledge.folders.move', 'Přesunout'],
    'folders.move_heading' => ['knowledge.folders.move_heading', 'Přesunout složku'],
    'folders.delete' => ['knowledge.folders.delete', 'Smazat'],
    'folders.delete_heading' => ['knowledge.folders.delete_heading', 'Smazat složku „:name“'],
    'folders.delete_description' => ['knowledge.folders.delete_description', 'Složka je prázdná. Smazání nelze vrátit zpět.'],
    'folders.delete_submit' => ['knowledge.folders.delete_submit', 'Smazat složku'],
    'folders.name' => ['knowledge.folders.name', 'Název složky'],
    'folders.parent' => ['knowledge.folders.parent', 'Nadřazená složka'],
    'folders.root' => ['knowledge.folders.root', 'Kořen (bez nadřazené složky)'],
    'folders.move_helper' => ['knowledge.folders.move_helper', 'Strom může mít nejvýše :max úrovní. Složky, kam přesun nejde, nelze vybrat.'],
    'folders.expand' => ['knowledge.folders.expand', 'Rozbalit :name'],
    'folders.collapse' => ['knowledge.folders.collapse', 'Sbalit :name'],
    'folders.menu' => ['knowledge.folders.menu', 'Akce složky :name'],
    'folders.chip' => ['knowledge.folders.chip', 'Složka: :path (včetně podsložek)'],
    'folders.chip_remove' => ['knowledge.folders.chip_remove', 'Zrušit filtr složky'],
    'folders.contents_articles' => ['knowledge.folders.contents_articles', '{1} :count článek (včetně archivovaných)|[2,4] :count články (včetně archivovaných)|[5,*] :count článků (včetně archivovaných)'],
    'folders.contents_folders' => ['knowledge.folders.contents_folders', '{1} :count podsložku|[2,4] :count podsložky|[5,*] :count podsložek'],
    'folders.contents_join' => ['knowledge.folders.contents_join', ':articles a :folders'],
    'folders.empty_heading' => ['knowledge.folders.empty_heading', 'Zatím tu nejsou žádné složky.'],
    'folders.empty_body' => ['knowledge.folders.empty_body', 'Složky řadí články do stromu. Vytvořte první.'],
    'folders.notifications.created' => ['knowledge.folders.notifications.created', 'Složka byla vytvořena'],
    'folders.notifications.renamed' => ['knowledge.folders.notifications.renamed', 'Složka byla přejmenována'],
    'folders.notifications.moved' => ['knowledge.folders.notifications.moved', 'Složka byla přesunuta'],
    'folders.notifications.deleted' => ['knowledge.folders.notifications.deleted', 'Složka byla smazána'],
    'folders.notifications.missing' => ['knowledge.folders.notifications.missing', 'Složka už neexistuje. Strom se načetl znovu.'],
    'folders.errors.name_required' => ['knowledge.folders.errors.name_required', 'Vyplňte název složky.'],
    'folders.errors.name_taken' => ['knowledge.folders.errors.name_taken', 'Složka s tímto názvem už na stejné úrovni existuje.'],
    'folders.errors.name_too_long' => ['knowledge.folders.errors.name_too_long', 'Název složky může mít nejvýše :max znaků.'],
    'folders.errors.create_depth' => ['knowledge.folders.errors.create_depth', 'Sem už podsložku přidat nejde: strom může mít nejvýše :max úrovní.'],
    'folders.errors.move_title' => ['knowledge.folders.errors.move_title', 'Složku se nepodařilo přesunout'],
    'folders.errors.cycle' => ['knowledge.folders.errors.cycle', 'Složku nelze přesunout do sebe ani do její vlastní podsložky.'],
    'folders.errors.depth' => ['knowledge.folders.errors.depth', 'Složku nelze přesunout sem: strom by byl hlubší než :max úrovní. Složka s podsložkami se počítá i s nimi.'],
    'folders.errors.delete_title' => ['knowledge.folders.errors.delete_title', 'Složku nelze smazat.'],
    'folders.errors.not_empty' => ['knowledge.folders.errors.not_empty', 'Složka není prázdná: obsahuje :contents. Přesuňte obsah jinam a zkuste to znovu.'],
    'tags.label' => ['knowledge.tags.label', 'Štítky'],
    'tags.helper' => ['knowledge.tags.helper', 'Štítky znalostí jsou oddělené od štítků klientů.'],
    'tags.filter_indicator' => ['knowledge.tags.filter_indicator', 'Všechny štítky: :tags'],
    'tags.errors.too_long' => ['knowledge.tags.errors.too_long', 'Štítek může mít nejvýše :max znaků.'],
    'tags.errors.too_many' => ['knowledge.tags.errors.too_many', 'Článek může mít nejvýše :max štítků.'],
    'links.section' => ['knowledge.links.section', 'Zařazení'],
    'links.folder' => ['knowledge.links.folder', 'Složka'],
    'links.folder_placeholder' => ['knowledge.links.folder_placeholder', 'Bez složky'],
    'links.client' => ['knowledge.links.client', 'Klient'],
    'links.client_placeholder' => ['knowledge.links.client_placeholder', 'Bez klienta'],
    'links.project' => ['knowledge.links.project', 'Projekt'],
    'links.project_placeholder' => ['knowledge.links.project_placeholder', 'Bez projektu'],
    'links.project_needs_client' => ['knowledge.links.project_needs_client', 'Nejdřív vyberte klienta.'],
    'links.errors.folder_missing' => ['knowledge.links.errors.folder_missing', 'Vybraná složka už neexistuje.'],
    'links.errors.client_missing' => ['knowledge.links.errors.client_missing', 'Vybraný klient už neexistuje.'],
    'links.errors.project_mismatch' => ['knowledge.links.errors.project_mismatch', 'Vybraný projekt nepatří k vybranému klientovi.'],
    'links.tab.title' => ['knowledge.links.tab.title', 'Znalosti'],
    'links.tab.add' => ['knowledge.links.tab.add', 'Přidat článek'],
    'links.tab.empty_heading' => ['knowledge.links.tab.empty_heading', 'Zatím tu nejsou žádné články.'],
    'links.tab.empty_client' => ['knowledge.links.tab.empty_client', 'K tomuto klientovi zatím není připojený žádný článek.'],
    'links.tab.empty_project' => ['knowledge.links.tab.empty_project', 'K tomuto projektu zatím není připojený žádný článek.'],
    'columns.folder' => ['knowledge.columns.folder', 'Složka'],
    'columns.tags' => ['knowledge.columns.tags', 'Štítky'],
    'columns.project' => ['knowledge.columns.project', 'Projekt'],
    'empty.folder_heading' => ['knowledge.empty.folder_heading', 'V této složce zatím nic není.'],
    'empty.folder_body' => ['knowledge.empty.folder_body', 'Zařaďte sem článek v jeho úpravách nebo napište nový.'],
    'empty.unfiled_heading' => ['knowledge.empty.unfiled_heading', 'Žádné nezařazené články.'],
    'empty.unfiled_body' => ['knowledge.empty.unfiled_body', 'Články bez složky se zobrazí tady.'],
    'empty.filtered_heading' => ['knowledge.empty.filtered_heading', 'Žádný článek neodpovídá filtrům.'],
    'empty.filtered_body' => ['knowledge.empty.filtered_body', 'Zrušte filtr nebo vyberte jiné štítky.'],
    'empty.reset_filters' => ['knowledge.empty.reset_filters', 'Zrušit filtry'],
    'notifications.classification_changed' => ['knowledge.notifications.classification_changed', 'Zařazení článku bylo uloženo, nová verze nevznikla.'],
    'notifications.stale_redacted_title' => ['knowledge.notifications.stale_redacted_title', 'Text článku byl mezitím změněn nebo redigován'],
    'notifications.stale_redacted_body' => ['knowledge.notifications.stale_redacted_body', 'Uložení se neprovedlo, aby se do článku nevrátil redigovaný text. Znovu otevřete článek a zkontrolujte změny.'],
    'versions.navigation' => ['knowledge.versions.navigation', 'Verze'],
    'versions.title' => ['knowledge.versions.title', 'Verze článku „:title“'],
    'versions.version_label' => ['knowledge.versions.version_label', 'Verze :n'],
    'versions.columns.version' => ['knowledge.versions.columns.version', 'Verze'],
    'versions.columns.author' => ['knowledge.versions.columns.author', 'Autor'],
    'versions.columns.created_at' => ['knowledge.versions.columns.created_at', 'Vytvořeno'],
    'versions.columns.markers' => ['knowledge.versions.columns.markers', 'Značky'],
    'versions.badges.current' => ['knowledge.versions.badges.current', 'Aktuální'],
    'versions.badges.restored_from' => ['knowledge.versions.badges.restored_from', 'Obnoveno z verze :n'],
    'versions.badges.redacted' => ['knowledge.versions.badges.redacted', 'Redigováno'],
    'versions.actions.view' => ['knowledge.versions.actions.view', 'Zobrazit verzi'],
    'versions.actions.compare_previous' => ['knowledge.versions.actions.compare_previous', 'Porovnat s předchozí'],
    'versions.actions.more' => ['knowledge.versions.actions.more', 'Další akce'],
    'versions.actions.back' => ['knowledge.versions.actions.back', 'Zpět na verze'],
    'versions.actions.view_current' => ['knowledge.versions.actions.view_current', 'Zobrazit aktuální verzi'],
    'versions.meta' => ['knowledge.versions.meta', 'Verze :n · :author · :date'],
    'versions.callouts.old' => ['knowledge.versions.callouts.old', 'Toto je starší verze. Aktuální je verze :current.'],
    'versions.callouts.redacted_heading' => ['knowledge.versions.callouts.redacted_heading', 'Tato verze byla redigována'],
    'versions.callouts.redacted_body' => ['knowledge.versions.callouts.redacted_body', 'Část textu, nebo celý text, byl nahrazen značkou [REDIGOVÁNO].'],
    'versions.callouts.archived' => ['knowledge.versions.callouts.archived', 'Článek je archivovaný. Chcete-li verzi obnovit, nejdřív článek obnovte.'],
    'diff.title' => ['knowledge.diff.title', 'Rozdíl verzí'],
    'diff.from' => ['knowledge.diff.from', 'Z verze'],
    'diff.to' => ['knowledge.diff.to', 'Na verzi'],
    'diff.option' => ['knowledge.diff.option', 'Verze :n · :date'],
    'diff.summary' => ['knowledge.diff.summary', 'Přidáno :added, odstraněno :removed řádků'],
    'diff.title_unchanged' => ['knowledge.diff.title_unchanged', 'Název se nezměnil: :title'],
    'diff.caption' => ['knowledge.diff.caption', 'Rozdíl mezi verzí :from a verzí :to'],
    'diff.column_old' => ['knowledge.diff.column_old', 'Řádek ve verzi :from'],
    'diff.column_new' => ['knowledge.diff.column_new', 'Řádek ve verzi :to'],
    'diff.column_marker' => ['knowledge.diff.column_marker', 'Změna'],
    'diff.column_text' => ['knowledge.diff.column_text', 'Text'],
    'diff.added' => ['knowledge.diff.added', 'Přidáno:'],
    'diff.removed' => ['knowledge.diff.removed', 'Odstraněno:'],
    'diff.collapsed' => ['knowledge.diff.collapsed', 'Nezměněných řádků: :count'],
    'diff.truncated' => ['knowledge.diff.truncated', 'Rozdíl je příliš dlouhý, zobrazeno prvních :max řádků.'],
    'diff.identical' => ['knowledge.diff.identical', 'Obě verze mají stejný text.'],
    'diff.same_version' => ['knowledge.diff.same_version', 'Vybrali jste stejnou verzi. Zvolte dvě různé verze.'],
    'diff.too_large' => ['knowledge.diff.too_large', 'Verze se liší příliš na to, aby šly porovnat po řádcích. Otevřete každou zvlášť.'],
    'diff.view_to' => ['knowledge.diff.view_to', 'Zobrazit verzi :n'],
    'restore.action' => ['knowledge.restore.action', 'Obnovit tuto verzi'],
    'restore.heading' => ['knowledge.restore.heading', 'Obnovit verzi :from?'],
    'restore.description' => ['knowledge.restore.description', 'Název a text se vrátí na stav verze :from a uloží se jako nová verze :next. Žádná verze se nesmaže.'],
    'restore.diff_caption' => ['knowledge.restore.diff_caption', 'Změny oproti aktuální verzi :current'],
    'restore.submit' => ['knowledge.restore.submit', 'Obnovit jako novou verzi'],
    'restore.submit_anyway' => ['knowledge.restore.submit_anyway', 'Obnovit i přesto'],
    'restore.cancel' => ['knowledge.restore.cancel', 'Zrušit'],
    'restore.identical' => ['knowledge.restore.identical', 'Tato verze se shoduje s aktuálním textem. Nová verze nevznikne.'],
    'restore.blocking_heading' => ['knowledge.restore.blocking_heading', 'Verzi nelze obnovit, obsahuje tajný údaj'],
    'restore.blocking_body' => ['knowledge.restore.blocking_body', 'Redigujte tajný údaj v této verzi a obnovte ji znovu.'],
    'restore.ambiguous_heading' => ['knowledge.restore.ambiguous_heading', 'Obnovovaná verze může obsahovat tajný údaj'],
    'restore.ambiguous_body' => ['knowledge.restore.ambiguous_body', 'Zkontrolujte uvedené řádky. Pokud je to v pořádku, obnovte verzi i přesto. Článek se pak označí jako citlivý.'],
    'restore.redact_link' => ['knowledge.restore.redact_link', 'Redigovat verzi'],
    'restore.notifications.restored' => ['knowledge.restore.notifications.restored', 'Verze :from byla obnovena jako verze :next'],
    'restore.notifications.restored_sensitive' => ['knowledge.restore.notifications.restored_sensitive', 'Verze :from byla obnovena jako verze :next a článek byl označen jako citlivý'],
    'restore.notifications.stale_title' => ['knowledge.restore.notifications.stale_title', 'Článek mezitím někdo změnil'],
    'restore.notifications.stale_body' => ['knowledge.restore.notifications.stale_body', 'Aktuální text článku se mezitím změnil. Zavřete okno, znovu otevřete obnovení a zkontrolujte změny.'],
    'redact.action' => ['knowledge.redact.action', 'Redigovat verzi'],
    'redact.heading' => ['knowledge.redact.heading', 'Redigovat verzi :n'],
    'redact.irreversible_heading' => ['knowledge.redact.irreversible_heading', 'Redigování nelze vrátit zpět'],
    'redact.irreversible_body' => ['knowledge.redact.irreversible_body', 'Vybraný text se v uložené verzi nenávratně přepíše značkou [REDIGOVÁNO]. Zobrazení, rozdíl i obnovení této verze pak použijí upravený text.'],
    'redact.rotate_heading' => ['knowledge.redact.rotate_heading', 'Skutečné řešení je tajný údaj změnit'],
    'redact.rotate_body' => ['knowledge.redact.rotate_body', 'Redigování odstraní jen kopii z Kokpitu. Únik vždy řešte změnou nebo zneplatněním údaje u jeho vydavatele (heslo, klíč, token).'],
    'redact.later_versions_keep' => ['knowledge.redact.later_versions_keep', 'Pozdější verze, které obsahují stejný text, si ho ponechají, dokud každou z nich neredigujete zvlášť.'],
    'redact.current_body' => ['knowledge.redact.current_body', 'Tato verze je aktuální: redigování se projeví i v aktuálním textu článku.'],
    'redact.source_label' => ['knowledge.redact.source_label', 'Text verze s čísly řádků'],
    'redact.source_truncated' => ['knowledge.redact.source_truncated', 'Zobrazeno prvních :max řádků. Další řádky lze vybrat podle jejich čísla.'],
    'redact.scope' => ['knowledge.redact.scope', 'Rozsah'],
    'redact.scope_lines' => ['knowledge.redact.scope_lines', 'Jen vybrané řádky'],
    'redact.scope_whole' => ['knowledge.redact.scope_whole', 'Celý text verze'],
    'redact.scope_helper' => ['knowledge.redact.scope_helper', 'U soukromého klíče zvolte raději celý text verze. Název verze se nerediguje.'],
    'redact.lines' => ['knowledge.redact.lines', 'Čísla řádků'],
    'redact.lines_helper' => ['knowledge.redact.lines_helper', 'Čísla oddělte čárkou, rozsah zapište pomlčkou, například 3, 7-9.'],
    'redact.reason' => ['knowledge.redact.reason', 'Důvod redigování'],
    'redact.reason_helper' => ['knowledge.redact.reason_helper', 'Do protokolu změn se zapíše jen to, že důvod existuje, ne jeho text. Nepište do něj tajný údaj.'],
    'redact.submit' => ['knowledge.redact.submit', 'Redigovat verzi'],
    'redact.cancel' => ['knowledge.redact.cancel', 'Zrušit'],
    'redact.errors.lines_required' => ['knowledge.redact.errors.lines_required', 'Vyberte alespoň jeden řádek.'],
    'redact.errors.lines_invalid' => ['knowledge.redact.errors.lines_invalid', 'Zapište čísla řádků, například 3, 7-9.'],
    'redact.errors.line_missing' => ['knowledge.redact.errors.line_missing', 'Řádek :n ve verzi neexistuje.'],
    'redact.errors.nothing_to_redact' => ['knowledge.redact.errors.nothing_to_redact', 'Vybrané řádky jsou prázdné nebo už redigované, není co redigovat.'],
    'redact.errors.too_long' => ['knowledge.redact.errors.too_long', 'Po nahrazení značkou by byl text verze delší než :max znaků. Zvolte celý text verze.'],
    'redact.errors.reason_required' => ['knowledge.redact.errors.reason_required', 'Vyplňte důvod redigování.'],
    'redact.errors.reason_too_long' => ['knowledge.redact.errors.reason_too_long', 'Důvod může mít nejvýše :max znaků.'],
    'redact.notifications.redacted' => ['knowledge.redact.notifications.redacted', 'Verze :n byla redigována'],
    'redact.notifications.key_extended' => ['knowledge.redact.notifications.key_extended', 'Výběr se rozšířil na celý soukromý klíč (řádky :from–:to).'],
    'redact.notifications.remaining_title' => ['knowledge.redact.notifications.remaining_title', 'Ve verzi může dál zůstat tajný údaj'],
    'redact.notifications.remaining_body' => ['knowledge.redact.notifications.remaining_body', 'Kontrola po redigování našla: :findings. Zvažte redigování celého textu verze.'],
    'redact.notifications.failed_title' => ['knowledge.redact.notifications.failed_title', 'Verzi se nepodařilo redigovat'],
    'redact.notifications.failed_body' => ['knowledge.redact.notifications.failed_body', 'Nic se nezměnilo. Zkuste to znovu.'],
]);

it('resolves every Phase 19 key to its agreed Czech wording', function (string $key, string $text): void {
    expect(__($key))->toBe($text);
})->with('phase 19 copy');

it('inflects the contents of a folder in the Czech plural forms', function (): void {
    expect(trans_choice('knowledge.folders.contents_articles', 1))->toBe('1 článek (včetně archivovaných)')
        ->and(trans_choice('knowledge.folders.contents_articles', 3))->toBe('3 články (včetně archivovaných)')
        ->and(trans_choice('knowledge.folders.contents_articles', 5))->toBe('5 článků (včetně archivovaných)')
        ->and(trans_choice('knowledge.folders.contents_folders', 1))->toBe('1 podsložku')
        ->and(trans_choice('knowledge.folders.contents_folders', 2))->toBe('2 podsložky')
        ->and(trans_choice('knowledge.folders.contents_folders', 7))->toBe('7 podsložek')
        ->and(__('knowledge.folders.contents_join', ['articles' => '3 články', 'folders' => '2 podsložky']))->toBe('3 články a 2 podsložky');
});

it('fills the placeholders of the depth refusal and the not-empty refusal', function (): void {
    expect(__('knowledge.folders.errors.depth', ['max' => 6]))->toEndWith('hlubší než 6 úrovní. Složka s podsložkami se počítá i s nimi.')
        ->and(__('knowledge.folders.errors.not_empty', ['contents' => '2 články a 1 podsložku']))
        ->toBe('Složka není prázdná: obsahuje 2 články a 1 podsložku. Přesuňte obsah jinam a zkuste to znovu.');
});

it('names the restored and redacted versions in Czech in the history and in the activity log', function (): void {
    expect(__('knowledge.versions.badges.restored_from', ['n' => 2]))->toBe('Obnoveno z verze 2')
        ->and(__('knowledge.versions.badges.redacted'))->toBe('Redigováno')
        ->and(__('kokpit.activity.events.version_restored'))->toBe('Verze obnovena')
        ->and(__('kokpit.activity.events.version_redacted'))->toBe('Verze redigována');
});

it('shows the redaction marker as [REDIGOVÁNO] on every screen that names it', function (): void {
    expect(__('knowledge.versions.callouts.redacted_body'))->toContain('[REDIGOVÁNO]')
        ->and(__('knowledge.redact.irreversible_body'))->toContain('[REDIGOVÁNO]');
});

it('leaves no Phase 19 string empty or equal to its own key', function (): void {
    $strings = Arr::dot(Arr::only(require lang_path('cs/knowledge.php'), ['folders', 'tags', 'links', 'versions', 'diff', 'restore', 'redact']));

    expect($strings)->not->toBe([]);

    foreach ($strings as $path => $text) {
        expect($text)->toBeString()->not->toBe('')->not->toBe('knowledge.'.$path);
    }
});
