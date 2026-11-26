{{--
    The compare page of two stored versions (VER-04, D-09): two selects that recompute the diff live, then the diff of
    the one diff view, or the note that the same version was chosen on both sides.
    Takes $options (version number => option label, newest first), $fromNo, $toNo, $sameVersion and, unless the same
    version is chosen, $result (VersionDiffResult), $fromTitle, $toTitle and $caption.
    Every text is printed through the escaped echo. Inline styles only: the panel theme does not compile utility
    classes of views outside the Filament sources.
--}}
<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:1rem;">
        <label style="display:grid;gap:0.25rem;min-width:14rem;">
            <span style="font-size:0.875rem;line-height:1.25rem;font-weight:600;">{{ __('knowledge.diff.from') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="from">
                    @foreach ($options as $number => $label)
                        <option value="{{ $number }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label style="display:grid;gap:0.25rem;min-width:14rem;">
            <span style="font-size:0.875rem;line-height:1.25rem;font-weight:600;">{{ __('knowledge.diff.to') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="to">
                    @foreach ($options as $number => $label)
                        <option value="{{ $number }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        {{-- Shown while a select change is on its way to the server; the previous diff stays until the new one arrives. --}}
        <div wire:loading.flex wire:target="from,to" role="status" style="align-items:center;padding-bottom:0.5rem;">
            <x-filament::loading-indicator style="width:1.5rem;height:1.5rem;" />
        </div>
    </div>

    @if ($sameVersion)
        <p style="margin:0;color:var(--gray-500);">{{ __('knowledge.diff.same_version') }}</p>
    @else
        @include('filament.knowledge.version-diff', [
            'result' => $result,
            'fromNo' => $fromNo,
            'toNo' => $toNo,
            'fromTitle' => $fromTitle,
            'toTitle' => $toTitle,
            'caption' => $caption,
            'maxHeight' => null,
        ])
    @endif
</x-filament-panels::page>
