<x-mail::message>
@if (strip_tags($body) !== '')
{!! $body !!}
@else
{{ __('We got your message and will reply soon.') }}
@endif

{{ __('Regards,') }}<br>
{{ $brand }}
</x-mail::message>
