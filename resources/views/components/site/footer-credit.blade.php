@props(['text', 'url' => ''])

@if ($url !== '')
    <a
        href="{{ $url }}"
        target="_blank"
        rel="noopener"
        data-footer-credit
        {{ $attributes->class('hover:underline') }}
    >{{ $text }}</a>
@else
    <span data-footer-credit {{ $attributes }}>{{ $text }}</span>
@endif
