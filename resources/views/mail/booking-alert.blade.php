<x-mail::message>
@if ($kind === 'clashed')
# {{ __('A late payment clashes with another booking') }}

{{ __('This visitor paid after their checkout had expired, and the time had been booked by someone else in the meantime. Contact them to agree on a new time or a refund.') }}
@elseif ($kind === 'cancelled')
# {{ __('Booking cancelled') }}
@elseif ($kind === 'rescheduled')
# {{ __('Booking moved') }}
@else
# {{ __('New booking') }}
@endif

**{{ $booking->service_name }}**<br>
{{ $when }}<br>
{{ $booking->name ?: __('No name given') }}
@if ($booking->email)
<br>{{ $booking->email }}
@endif
@if ($booking->phone)
<br>{{ $booking->phone }}
@endif

@if ($booking->notes)
<x-mail::panel>
{{ $booking->notes }}
</x-mail::panel>
@endif

@if ($refundUrl)
{{ __('This booking was paid. Refund it from the order if you need to.') }}

<x-mail::button :url="$refundUrl">
{{ __('Refund the payment') }}
</x-mail::button>
@endif

<x-mail::button :url="$viewUrl">
{{ __('View booking') }}
</x-mail::button>
</x-mail::message>
