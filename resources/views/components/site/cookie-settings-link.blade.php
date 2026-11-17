@props(['label'])

<button
    type="button"
    onclick="window.wireupConsent && window.wireupConsent.open()"
    data-cookie-settings
    {{ $attributes->class('cursor-pointer hover:underline') }}
>
    {{ $label }}
</button>
