@php($brand = \App\Domain\Notifications\Mail\MailBrand::current())
<x-mail::layout>
{{-- Header: the supplier name (D-03). Every value is read through MailBrand. --}}
<x-slot:header>
<x-mail::header>
{{ $brand->name }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: the same lines as the HTML part, each only when it has content. --}}
<x-slot:footer>
<x-mail::footer>
{{ $brand->name }}
@if ($brand->addressLine() !== null)
{{ $brand->addressLine() }}
@endif
@if ($brand->companyId !== null || $brand->vatId !== null)
{{ implode(' · ', array_filter([
    $brand->companyId !== null ? __('mail_layout.company_id').' '.$brand->companyId : null,
    $brand->vatId !== null ? __('mail_layout.vat_id').' '.$brand->vatId : null,
])) }}
@endif
@if ($brand->email !== null || $brand->phone !== null || $brand->website !== null)
{{ implode(' · ', array_filter([$brand->email, $brand->phone, $brand->website])) }}
@endif
@if ($brand->registrationNote !== null)
{{ $brand->registrationNote }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
