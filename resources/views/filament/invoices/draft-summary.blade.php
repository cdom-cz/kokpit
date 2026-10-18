{{--
    The summary block of the draft editor (UI-SPEC Surface C5). Every figure arrives as text computed on the server
    (ruling R-25-1); there is no VAT line in the non-payer mode (IN-08). Inline styles only (UI-SPEC F-11): the
    panel theme does not compile utility classes of views outside the Filament sources.
--}}
<x-filament::section :heading="__('invoices.draft.sections.summary')">
    <dl style="display: grid; gap: 0.5rem; margin: 0;">
        @if (filled($subtotal))
            <div style="display: flex; justify-content: space-between; gap: 1rem;">
                <dt>{{ __('invoices.draft.summary.subtotal') }}</dt>
                <dd style="margin: 0; font-variant-numeric: tabular-nums;">{{ $subtotal }}</dd>
            </div>
        @endif

        @if (filled($vat))
            <div style="display: flex; justify-content: space-between; gap: 1rem;">
                <dt>{{ __('invoices.draft.summary.vat') }}</dt>
                <dd style="margin: 0; font-variant-numeric: tabular-nums;">{{ $vat }}</dd>
            </div>
        @endif

        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 0.5rem 1rem;">
            <dt style="font-weight: 600;">{{ __('invoices.draft.summary.total') }}</dt>
            <dd data-draft-total style="margin: 0; font-size: 1.875rem; line-height: 2.25rem; font-weight: 600; font-variant-numeric: tabular-nums;">{{ $total }}</dd>
        </div>
    </dl>

    @if (filled($czk))
        <p style="margin: 1rem 0 0; color: var(--gray-500);">{{ $czk }}</p>
        <p style="margin: 0.25rem 0 0; color: var(--gray-500); font-size: 0.875rem;">{{ __('invoices.draft.summary.czk_frozen') }}</p>
    @elseif (filled($czkMissing))
        <p style="margin: 1rem 0 0; color: var(--gray-500);">{{ $czkMissing }}</p>
    @endif
</x-filament::section>
