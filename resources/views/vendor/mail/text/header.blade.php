@props(['url' => null])
{!! trim((string) $slot) !!}@if ($url) ({{ $url }})@endif
