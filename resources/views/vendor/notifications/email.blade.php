<x-mail::message>
{{-- Greeting: the notification's own, otherwise the neutral layout greeting for every level. --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
# {{ __('mail_layout.greeting') }}
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation: only when the notification sets one, so no second sign-off stands under the supplier footer. Each line is escaped. --}}
@if (! empty($salutation))
{!! implode('<br>', array_map(e(...), explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $salutation)))) !!}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('mail_layout.button_fallback') }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
