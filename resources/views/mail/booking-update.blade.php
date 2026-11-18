<x-mail::message>
@if ($kind === 'cancelled')
# {{ __('Your booking is cancelled') }}

{{ __('This booking won’t go ahead:') }}
@elseif ($kind === 'rescheduled')
# {{ __('Your booking has moved') }}

{{ __('Here is the new time:') }}
@elseif ($kind === 'reminder')
# {{ __('See you soon') }}

{{ __('A reminder of your booking:') }}
@else
# {{ __('Your booking is confirmed') }}

{{ __('Thanks for booking. Here are the details:') }}
@endif

**{{ $booking->service_name }}**<br>
{{ $when }} ({{ $booking->timezone }})<br>
{{ __(':count min', ['count' => $booking->duration_minutes]) }}
@if ($location !== '')
<br>{{ $location }}
@endif

{{ __('Booking reference: :reference', ['reference' => $booking->reference]) }}

@if ($manageUrl)
<x-mail::button :url="$manageUrl">
{{ __('Change or cancel') }}
</x-mail::button>
@endif

{{ __('Regards,') }}<br>
{{ $brand }}
</x-mail::message>
