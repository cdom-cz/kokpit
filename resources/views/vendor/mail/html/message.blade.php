@php($brand = \App\Domain\Notifications\Mail\MailBrand::current())
<x-mail::layout>
{{-- Header: the supplier logo as an inline cid image, else the supplier name as text (D-03). The cid is emitted only when
     MailBrand holds the logo, the same decider the EmbedMailLogo listener uses, so there is never a dangling cid.
     Every value is read through MailBrand, never from the settings directly. --}}
<x-slot:header>
<x-mail::header :url="$brand->website">
@if ($brand->hasLogo())
<img src="cid:{{ \App\Domain\Notifications\Mail\MailBrand::LOGO_NAME }}" alt="{{ $brand->name }}" width="{{ $brand->logoWidth }}" height="{{ $brand->logoHeight }}" style="border: 0; display: block; margin: 0 auto;">
@else
{{ $brand->name }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: the supplier details, each line only when it has content. --}}
<x-slot:footer>
<x-mail::footer>
<p>{{ $brand->name }}</p>
@if ($brand->addressLine() !== null)
<p>{{ $brand->addressLine() }}</p>
@endif
@if ($brand->companyId !== null || $brand->vatId !== null)
<p>{{ implode(' · ', array_filter([
    $brand->companyId !== null ? __('mail_layout.company_id').' '.$brand->companyId : null,
    $brand->vatId !== null ? __('mail_layout.vat_id').' '.$brand->vatId : null,
])) }}</p>
@endif
@if ($brand->email !== null || $brand->phone !== null || $brand->website !== null)
<p>{{ implode(' · ', array_filter([$brand->email, $brand->phone, $brand->website])) }}</p>
@endif
@if ($brand->registrationNote !== null)
<p>{{ $brand->registrationNote }}</p>
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
