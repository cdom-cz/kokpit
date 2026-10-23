{{-- Plain text: the same header, body, subcopy and footer as the HTML part, tags stripped. A line break the layout itself
     writes (the lines of a salutation) becomes a real one first; typed text is encoded, so it can not hold one. The
     escape backslashes the Markdown of the HTML part needs are removed again (MailText), so the text reads as written. --}}
{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) ($header ?? ''))) !!}

{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) preg_replace('/<br\s*\/?>/i', "\n", (string) $slot))) !!}
@isset($subcopy)

{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) $subcopy)) !!}
@endisset

{!! \App\Domain\Notifications\Mail\MailText::unescapeMarkdown(strip_tags((string) ($footer ?? ''))) !!}
