@php
    use App\Domain\Payments\Enums\PayPageOutcome;
    use Filament\Support\Icons\Heroicon;

    /*
     * The public pay page (UI-SPEC Surface A, R11-08-1): a standalone document that loads the panel's assets, uses only
     * anonymous Blade components and inline styles from the UI-SPEC scale, and has no script, no form and no CSRF meta.
     * Declared sizes are 14, 16, 24 and 48 px; weights 400 and 600; spacing 8, 16, 24 and 32 px.
     */
    $icon = match ($outcome) {
        PayPageOutcome::Preparing => [Heroicon::OutlinedClock, 'var(--info-600)'],
        PayPageOutcome::Paid => [Heroicon::OutlinedCheckCircle, 'var(--success-600)'],
        PayPageOutcome::Cancelled => [Heroicon::OutlinedXCircle, 'var(--gray-500)'],
        default => [Heroicon::OutlinedExclamationCircle, 'var(--gray-500)'],
    };

    $brand = trim(strip_tags(filament()->getBrandName()));
    $title = implode(' / ', array_map(static fn (array $card): string => $card['heading'], $cards));
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" class="fi">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />

        <title>{{ $title }}@if (filled($brand)) - {{ $brand }}@endif</title>

        @filamentStyles

        {{ filament()->getTheme()->getHtml() }}
        {{ filament()->getFontPreloadHtml() }}
        {{ filament()->getFontHtml() }}

        <style>
            :root {
                --font-family: '{!! filament()->getFontFamily() !!}';
            }

            .pay-card {
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .pay-card + .pay-card {
                margin-top: 2rem;
                padding-top: 2rem;
                border-top: 1px solid var(--gray-200);
            }

            .pay-heading {
                margin: 1rem 0 0;
                font-size: 1.5rem;
                line-height: 1.33;
                font-weight: 600;
                color: var(--gray-950);
                overflow-wrap: anywhere;
            }

            .pay-body {
                margin: 0.5rem 0 0;
                font-size: 0.875rem;
                line-height: 1.43;
                font-weight: 400;
                color: var(--gray-600);
                overflow-wrap: anywhere;
            }

            .pay-action {
                margin-top: 1.5rem;
            }

            @media (prefers-color-scheme: dark) {
                body.fi-body { background-color: var(--gray-950); }
                .fi-simple-main { background-color: var(--gray-900); }
                .pay-card + .pay-card { border-top-color: var(--gray-700); }
                .pay-heading { color: #fff; }
                .pay-body { color: var(--gray-400); }
            }
        </style>
    </head>

    <body class="fi-body fi-panel-{{ filament()->getId() }}">
        <div class="fi-simple-layout">
            <div class="fi-simple-main-ctn">
                <main id="fi-main-content" class="fi-simple-main fi-width-lg">
                    <div class="fi-simple-page">
                        <div class="fi-simple-page-content">
                            @foreach ($cards as $card)
                                <section class="pay-card" lang="{{ $card['lang'] }}">
                                    <x-filament::icon
                                        :icon="$icon[0]"
                                        aria-hidden="true"
                                        style="width: 3rem; height: 3rem; color: {{ $icon[1] }};"
                                    />

                                    <h1 class="pay-heading">{{ $card['heading'] }}</h1>
                                    <p class="pay-body">{{ $card['body'] }}</p>

                                    @if ($card['retry'] !== null && $retryHref !== null)
                                        <div class="pay-action">
                                            <x-filament::button tag="a" :href="$retryHref" :spa-mode="false">
                                                {{ $card['retry'] }}
                                            </x-filament::button>
                                        </div>
                                    @endif
                                </section>
                            @endforeach

                            @if (filled($brand))
                                <p class="pay-body" style="text-align: center; margin-top: 2rem;">{{ $brand }}</p>
                            @endif
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </body>
</html>
