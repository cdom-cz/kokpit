<x-mail::message>
{{-- D-01: the Admin's text is escaped into HTML paragraph blocks and never enters the Markdown slot as it is. --}}
{!! \App\Domain\Notifications\Mail\PlainTextHtml::paragraphs($body) !!}

@if ($payLink !== null)
<p>{{ $payLink['lead'] }}</p>

<x-mail::button :url="$payLink['url']">
{{ $payLink['label'] }}
</x-mail::button>

<p>{{ __('mail_layout.button_fallback') }}<br>
{{ $payLink['url'] }}</p>
@endif
</x-mail::message>
