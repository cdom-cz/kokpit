# Phase 02 — UI Review

**Audited:** 2026-10-07
**Baseline:** UI-SPEC.md (approved)
**Screenshots:** Not captured (no dev server on localhost:3000, 5173 or 8080 — code-only audit)
**Interaction captures:** Off (workflow.ui_interaction_capture is false)

---

## Pillar Scores

| Pillar | Score | Key Finding |
|--------|-------|-------------|
| 1. Copywriting | 3/4 | Czech strings complete, role label mismatch recorded in plan 02-09 as intentional (UI-SPEC says "Správce"; plan chose "Administrátor") |
| 2. Visuals | 2/4 | No rendered output available; empty state icon present, avatar implemented locally, but contrast and focus ring cannot be verified without screenshots |
| 3. Color | 2/4 | **BLOCKER:** Primary color is Amber (AdminPanelProvider.php:63), UI-SPEC requires Indigo (A-3). No hardcoded colors in app code. |
| 4. Typography | 3/4 | Stock Filament Inter font, proper display formats set via LocalisationServiceProvider, Czech diacritics supported. All sizes via Filament. |
| 5. Spacing | 3/4 | No arbitrary spacing in app code; all through Filament components. No scale violations detected. |
| 6. Experience Design | 2/4 | Empty state implemented, but loading states, button disabled behavior, dark-mode rendering and interaction flows cannot be verified without browser. Stock Filament 2FA, login and profile pages rely on vendor behavior. |

**Overall: 15/24**

---

## Top 3 Priority Fixes

1. **Change primary color from Amber to Indigo (AdminPanelProvider.php:63)** — Currently `Color::Amber` breaks UI-SPEC contract A-3 and weakens dark-mode contrast per spec. Change to `Color::Indigo` — this is a one-line fix with no other dependencies.

2. **Verify dark-mode button contrast in a browser** — UI-SPEC Color section notes that Amber with white text measures ~3.2:1 (below WCAG AA). Indigo-500 in dark mode must measure ≥4.5:1. The phase gate gate (02-13) is listed as responsible for this verification but it was not performed in code-only audit.

3. **Verify all Czech diacritics render in Inter on login, 2FA set-up and profile pages** — UI-SPEC Typography requires Czech characters (ě, š, č, ř, ž, ý, ů, ď, ť, ň) render in Inter, not fallback. This is marked as a backstop item for the phase gate but cannot be audited without screenshots.

---

## Detailed Findings

### Pillar 1: Copywriting (3/4)

**FINDING: UI-SPEC copywriting contract incomplete against code**

- **PASS:** All Czech UI strings present and correct:
  - Login: "Přihlásit se" (actions.php, auth.php confirm it)
  - 2FA challenge: "Potvrdit přihlášení" (actions.php `confirm`)
  - Profile save: "Uložit" (actions.php `save`)
  - Error: "Tyto přihlašovací údaje neodpovídají žádnému záznamu." (auth.php `failed`)
  - Empty state: "Zatím tu nic není" + role-specific descriptions (kokpit.php `dashboard.empty_heading`, `empty_description_admin`, `empty_description_partner`)
  - Install prompts: "Zadejte e-mail správce", "Zadejte heslo (nejméně 12 znaků)" (kokpit.php `install.prompt_email`, `prompt_password`)

- **WARNING: Role label mismatch recorded as intentional by plan 02-09**
  - UI-SPEC Copywriting Contract says `"Správce"` (line 253)
  - Plan 02-09 Deviation #6 states: "Admin role label is 'Administrátor', not 'Správce'. The plan wins."
  - No code change required per orchestrator instruction, but inconsistency noted: the app says "Administrátor" (enum label in `lang/cs/enums.php` or inherited from plan 02-09), while CLI and UI-SPEC say "Správce" for the account type
  - **Impact:** Cosmetic; no user-facing UI renders the enum label in Phase 2

- **PASS:** No generic labels ("OK", "Click here", "Submit") in app copywriting; all strings are specific

- **PASS:** No indication text is truncated or missing in any form field label

**Score Justification:** 3/4 because copywriting is complete and correct, but the role label inconsistency (plan vs spec) remains unresolved, classified as non-blocking by the plan.

---

### Pillar 2: Visuals (2/4)

**FINDING: Code-only audit cannot verify rendered visual hierarchy, focus ring, or contrast**

- **PASS:** Component structure uses stock Filament layouts:
  - Login/set-up: simple-page card (Filament's `FilamentInfoWidget` removed, `AccountWidget` removed per UI-SPEC)
  - Profile: authenticated page with sidebar (stock Filament structure)
  - Dashboard: custom `App\Filament\Pages\Dashboard` replaces stock, implements empty state with icon

- **PASS:** Avatar provider implemented locally:
  - `app/Support/InitialsAvatarProvider.php` generates inline SVG (no third-party request to ui-avatars.com)
  - Neutral gray background (#52525b = zinc-600) with white text
  - Implementation at AdminPanelProvider.php:61 `->defaultAvatarProvider(InitialsAvatarProvider::class)`

- **PASS:** Icon usage:
  - Dashboard empty state: `Heroicon::OutlinedInbox` (outline style, stock Filament icon)
  - No second icon set imported

- **WARNING:** Visual hierarchy (focal point on login, set-up, challenge) cannot be verified:
  - Login card is centered on dominant (zinc-50/950) background ✓ (expected Filament stock)
  - Primary button color cannot be verified without rendering (currently Amber, spec requires Indigo)
  - Focus ring color cannot be verified without interaction screenshots
  - Dark-mode rendering not visible (spec requires light/dark/system theme switcher, which is Filament stock)

- **WARNING:** Spacing and padding consistency cannot be verified without screenshots:
  - Forms use Filament's configureUsing defaults (no app-level spacing classes)
  - No excessive gaps or missing padding visible in code

**Score Justification:** 2/4 because component structure is sound and avatar is local, but visual hierarchy verification requires rendered screenshots that code-only audit cannot provide. The primary color issue (Amber vs Indigo) degrades the visual contract.

---

### Pillar 3: Color (2/4)

**FINDING: Primary color mismatch with UI-SPEC contract (A-3) — BLOCKER**

- **BLOCKER: Primary color is Amber, not Indigo**
  - **File:** `app/Providers/Filament/AdminPanelProvider.php:63`
  - **Current:** `'primary' => Color::Amber,`
  - **Required:** `'primary' => Color::Indigo` (per UI-SPEC A-3)
  - **Reason (UI-SPEC):** Amber collides with Filament's warning colour; Indigo-600 light mode gives 6.3:1 contrast with white (AA+), while Amber gives ~3.2:1 (below AA)
  - **Note:** Plan 02-09 deviation #9 states A-3 is "out of scope for this plan", so the colour was not applied
  - **Impact:** Every screen (login, 2FA, profile, dashboard) renders with wrong primary throughout the SPA, affecting:
    - Primary button color
    - Active sidebar item highlight
    - Text link color in forms and modals
    - Keyboard focus ring
    - Checkbox checked state

- **PASS:** No hardcoded colors in `app/` code
  - All semantic colors use Filament's `Color::` enum through the panel configuration
  - Avatar inline SVG uses literal hex only for background (#52525b = zinc-600)

- **PASS:** Semantic color usage matches contract:
  - Success (green) for "Uloženo" toast (Filament stock)
  - Destructive (red) for "Vypnout" action (Filament stock)
  - No amber or blue (warning/info) used in Phase 2 screens

- **WARNING:** Dark-mode contrast of current Amber button cannot be verified:
  - UI-SPEC states: Amber with white text in dark mode ~3.2:1 (below AA)
  - Indigo-500 in dark mode should measure ≥4.5:1
  - **Deferred to phase gate 02-13** per Copywriting Contract footnote

- **PASS:** 60/30/10 distribution would be correct once primary is fixed:
  - 60% dominant (zinc-50/950 page background) ✓
  - 30% secondary (white/zinc-900 card backgrounds) ✓
  - 10% accent (indigo, once applied, for button/focus/active/links) ✓

**Score Justification:** 2/4 because the colour system is architecturally sound and no hardcoded values exist, but the primary colour mismatch is a BLOCKER contract violation that affects every interactive element's visual identification.

---

### Pillar 4: Typography (3/4)

**FINDING: Typography contract substantially met; stock Filament fonts and sizes, Czech support confirmed**

- **PASS:** Font family is stock Filament Inter Variable:
  - No `Panel::font()` call in code (which would switch to Bunny CDN per UI-SPEC Design System)
  - Files ship in `filament/filament` package, including `latin-ext` subset for Czech diacritics
  - **File:** `app/Providers/Filament/AdminPanelProvider.php:42-85` (no custom font configuration)

- **PASS:** Declared font sizes used via Filament (no custom sizes in app):
  - Body: 14px (`text-sm`, 400 weight) — inputs, buttons, labels, menu items, sidebar items (Filament stock)
  - Heading: 16px (`text-base`, 600 weight) — section/modal headings (Filament stock)
  - Display: 24px / 30px (`text-2xl` / `text-3xl`, 600 weight) — page headings below and above `sm` (Filament stock)
  - **No other sizes introduced by app code**

- **PASS:** Declared font weights (400 and 600) plus accepted Filament stock (500, 700):
  - UI-SPEC A-9 notes: "stock Filament also renders weights 500 and 700, accepted as out of scope"
  - `app/` code adds no new weights

- **PASS:** Display formats in LocalisationServiceProvider set for Czech:
  - Date: `j. n. Y` (6. 1. 2026 format)
  - Date-time: `j. n. Y H:i` and with seconds `j. n. Y H:i:s`
  - Time: `H:i` (24-hour)
  - **File:** `app/Providers/LocalisationServiceProvider.php` (created in plan 02-08)
  - **Verified by tests:** `tests/Feature/Localisation/FormatsTest.php`

- **PASS:** Czech diacritics support:
  - Test `tests/Feature/Localisation/LangEncodingTest.php` verifies all `lang/cs` strings are valid UTF-8 NFC
  - Inter Variable includes Czech `latin-ext` subset
  - **No fallback face expected**

- **NO ISSUES:** No line-height overrides in app code; Tailwind 4 stock pairings used (1.43, 1.5, 1.33, 1.2)

**Score Justification:** 3/4 because typography is complete and correct, using stock Filament throughout, but phase gate verification of Czech diacritics rendering in Inter (UI-SPEC Typography footnote) requires visual inspection in 02-13, which was not performed in code-only audit.

---

### Pillar 5: Spacing (3/4)

**FINDING: Spacing scale observed via Filament configuration; no arbitrary values**

- **PASS:** No hand-written spacing classes in `app/Filament` or `resources/`:
  - All layout delegated to Filament components
  - No `p-`, `px-`, `py-`, `m-`, `mx-`, `my-`, `gap-`, or `space-` classes in application code
  - **Grep result:** Zero matches in `app/Filament`, zero in Dashboard.php

- **PASS:** LocalisationServiceProvider sets form grid defaults:
  - **File:** `app/Providers/LocalisationServiceProvider.php`
  - Schema and Table `configureUsing` calls establish consistent spacing for all form layouts
  - No arbitrary `[...]px` or `[...]rem` values

- **PASS:** EmptyState on Dashboard uses stock Filament sizing:
  - Dashboard.php line 40-44 uses `EmptyState::make()` with no spacing overrides
  - Filament's default card padding (lg = 24px per UI-SPEC) and section inner padding (lg = 24px) applied

- **PASS:** Avatar SVG viewBox matches Filament standards:
  - 64×64px, appropriate for menu and sidebar (Filament's stock interactive target 36px minimum plus padding)
  - No scaling issues expected

- **PASS:** No exceptions from UI-SPEC spacing scale (12px inside buttons, 36px target height):
  - These are Filament-internal; application code does not override

- **NO CUSTOM SPACING FOUND:** Audit confirms "no custom spacing is authored in this phase" (UI-SPEC Spacing Exceptions)

**Score Justification:** 3/4 because spacing is entirely delegated to Filament with no custom values, meeting the contract, but the actual rendered padding and gap sizes cannot be verified without screenshots.

---

### Pillar 6: Experience Design (2/4)

**FINDING: State coverage incomplete for code-only audit; interactive flows exist but cannot be verified without browser**

- **PASS:** Empty state implemented:
  - Dashboard.php lines 40-44: `EmptyState::make()` with role-specific description
  - Admin text: "Nástěnka se naplní, jakmile v Kokpitu přibudou klienti, projekty a odpracovaný čas."
  - Partner text: "Jakmile pro vás bude něco připraveno, objeví se to tady."
  - **Matches UI-SPEC Copywriting Contract exactly**

- **PASS:** Error handling (backend code confirmed):
  - Invalid credentials: `auth.php` `failed` message ("Tyto přihlašovací údaje...")
  - Invalid 2FA code: Filament stock (not app-specific)
  - Recovery code invalid: Filament stock
  - Rate limit: Filament stock (tested in 02-09)
  - **Verified by tests:** `tests/Feature/Auth/TwoFactorEnforcementTest.php` and plan 02-09 summary

- **PASS:** Loading states rely on Filament stock:
  - Login button spinner and disabled state: `Panel::login()` provides
  - 2FA button behavior: Filament's `AppAuthentication::make()`
  - Profile save button: Filament's form action handler
  - **Cannot verify visual spinner appearance or button opacity without screenshots**

- **PASS:** Access control and Partner isolation:
  - Dashboard `#[AccessRule(PartnerAllowed)]` with `EnforcesPageAccessRule` trait
  - Profile page (Filament stock) respects `->profile()`
  - 2FA set-up page (Filament stock) respects `multiFactorAuthentication()` configuration
  - Partner without `client_id` sees 403 or dashboard empty state (tests confirm in 02-11)

- **PASS:** Dark mode:
  - Filament stock theme switcher configured via `Panel::default()` (system/light/dark)
  - No custom dark-mode classes in app code

- **WARNING: Focus ring visibility cannot be verified without interaction**
  - UI-SPEC Copywriting Contract: "Every screen is usable by keyboard alone; the visible focus ring is the accent colour"
  - Accent is currently Amber (not Indigo as specified)
  - Ring visibility, thickness and animation cannot be assessed without browser

- **WARNING: Responsive behaviour at mobile width cannot be verified**
  - UI-SPEC Screens contract: "Responsive behaviour is Filament's stock: simple-page cards become full width with 24px padding on mobile; modals become full-width sheets on small screens"
  - Dashboard EmptyState responsive behaviour relies on Filament stock
  - Cannot verify padding, text wrapping or layout shift without screenshots

- **WARNING: 2FA interaction flow not visible**
  - QR code rendering as locally generated inline image (code confirms: Filament `AppAuthentication` does this)
  - Recovery codes listed with copy and download links (Filament stock)
  - Manual key as copyable text with "Zkopírováno" tooltip (Filament stock, not app-controlled)
  - **No code audit of Livewire state or interaction sequencing possible**

- **WARNING: No custom loading skeleton**
  - Dashboard: no data fetched, no skeleton needed ✓
  - Pages (login, profile, 2FA): stock Filament, no custom skeleton
  - SPA progress bar: stock Filament top progress bar (accent colour, currently Amber)

- **LIMITATION: Disabled state of actions cannot be verified**
  - UI-SPEC: "Continue is hidden until a provider is enabled (stock behaviour), so there is no path to the panel without 2FA"
  - This is Filament stock, not app-controlled
  - Cannot verify button appearance or cursor style without screenshots

**Score Justification:** 2/4 because the state coverage is architecturally sound (empty, error, loading, access control all present), but interaction visibility, button styling, focus rings, dark-mode rendering, responsive layout and keyboard accessibility cannot be verified in a code-only audit.

---

## Registry Safety

**No registry audit required:** `components.json` does not exist and UI-SPEC.md confirms `shadcn_initialized: false` (line 5). Filament's official packages (`filament/*` from Packagist) were cleared by Phase 2 research (02-RESEARCH.md Package Legitimacy Audit) and passed the AGPL-compatible licence allowlist (plan 02-12). No third-party UI registries or Filament plugins are used in Phase 2.

---

## Files Audited

- `app/Providers/Filament/AdminPanelProvider.php` — Panel configuration, primary colour setting
- `app/Filament/Pages/Dashboard.php` — Custom dashboard, empty state implementation, access rule
- `app/Filament/Concerns/EnforcesPageAccessRule.php` — Access rule enforcement trait
- `app/Support/InitialsAvatarProvider.php` — Local avatar generation (no third-party request)
- `app/Providers/LocalisationServiceProvider.php` — Typography display formats and Czech defaults
- `lang/cs/kokpit.php` — App-specific Czech copywriting (install, 2FA reset, dashboard)
- `lang/cs/auth.php` — Generated Czech auth strings from laravel-lang
- `lang/cs/actions.php` — Generated Czech action labels from laravel-lang
- `lang/cs/http-statuses.php` — Generated Czech status messages from laravel-lang
- `lang/cs/enums.php` — Role labels (Administrátor, Partner)
- `config/app.php` — Locale, timezone, faker locale defaults
- `tests/Feature/Auth/TwoFactorEnforcementTest.php` — Auth flow and visual surface references
- `tests/Feature/Localisation/FormatsTest.php` — Typography and display format verification
- `tests/Feature/Localisation/EnumLabelsTest.php` — Czech enum label validation
- `tests/Isolation/PanelAccessTest.php` — Dashboard access and empty state verification
- `tests/Arch/PanelRegistryTest.php` — Filament component declaration scanning
