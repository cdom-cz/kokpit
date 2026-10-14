{{--
    The footer of the table "Nevyfakturováno tento měsíc": the line "Celkem" over every client with unbilled time,
    then a line with the notes that apply. Rendered inside the table's <tfoot><tr>, so it holds only cells; the
    second line opens its own row. The client-currency column stays blank because currencies mix; CZK is the only
    column that sums across clients. Every value goes through {{ }}.
--}}
<td class="fi-ta-cell" style="padding: 0.5rem 1rem; font-weight: 600;">
    {{ __('kokpit.dashboard.unbilled.total') }}
</td>
<td class="fi-ta-cell" style="padding: 0.5rem 1rem; font-weight: 600; text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums;">
    {{ $hours }}
</td>
<td class="fi-ta-cell"></td>
<td class="fi-ta-cell" style="padding: 0.5rem 1rem; font-weight: 600; text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums;">
    {{ $czk ?? __('kokpit.time.empty_value') }}
</td>

@if ($fixed || $czkMissing > 0)
    </tr><tr>
    <td class="fi-ta-cell" colspan="4" style="padding: 0.5rem 1rem;">
        @if ($fixed)
            <p class="fi-sc-text" style="margin: 0;">{{ __('kokpit.dashboard.unbilled.fixed_note') }}</p>
        @endif

        @if ($czkMissing > 0)
            <p class="fi-sc-text" style="margin: 0;">{{ __('kokpit.dashboard.unbilled.missing_rate_note', ['count' => $czkMissing]) }}</p>
        @endif
    </td>
@endif
