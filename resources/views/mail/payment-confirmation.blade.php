@php
    use App\Domain\Notifications\Mail\MailBrand;
    use App\Domain\Notifications\Mail\MailText;
@endphp
<x-mail::message>
# {{ __('payment_mail.greeting') }}

{{ __('payment_mail.received', [
    'amount' => MailText::escapeMarkdown($amount),
    'date' => MailText::escapeMarkdown($paidOn),
    'number' => MailText::escapeMarkdown($number),
]) }}

@if ($remaining === null)
{{ __('payment_mail.paid_in_full') }}
@else
{{ __('payment_mail.remaining', ['remaining' => MailText::escapeMarkdown($remaining)]) }}
@endif

{{ __('payment_mail.thanks') }}

{{ __('mail_layout.regards') }}<br>
{{ MailText::escapeMarkdown(MailBrand::current()->name) }}
</x-mail::message>
