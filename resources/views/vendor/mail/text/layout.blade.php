{{-- Plain text: the same header, body, subcopy and footer as the HTML part, tags stripped. The escape backslashes the
     Markdown of the HTML part needs are removed again (MailText), so the text reads as written. --}}
{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) ($header ?? ''))) !!}

{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) $slot)) !!}
@isset($subcopy)

{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) $subcopy)) !!}
@endisset

{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) ($footer ?? ''))) !!}
