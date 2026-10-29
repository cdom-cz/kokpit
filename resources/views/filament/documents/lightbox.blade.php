{{-- The single-image lightbox body (D-08): one picture, centred, never taller than 80% of the window. The link in $src was minted when this view rendered. --}}
<div
    style="padding:1rem;"
    x-data="{ loaded: false, failed: false }"
    x-init="
        const image = $refs.image
        if (image && image.complete) {
            if (image.naturalWidth > 0) { loaded = true } else { failed = true }
        }
    "
>
    <div x-show="! loaded && ! failed" style="display:flex;justify-content:center;padding:2rem 0;">
        <x-filament::loading-indicator style="width:2rem;height:2rem;" />
    </div>

    <img
        x-ref="image"
        src="{{ $src }}"
        alt="{{ $name }}"
        x-on:load="loaded = true"
        x-on:error="failed = true"
        x-show="! failed"
        x-bind:style="loaded ? '' : 'visibility:hidden;height:0;'"
        style="display:block;margin:0 auto;max-width:100%;max-height:80vh;object-fit:contain;"
    >

    <p x-show="failed" x-cloak style="text-align:center;">{{ __('documents.errors.preview_failed') }}</p>
</div>
