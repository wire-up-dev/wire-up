<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\ShopService;
use App\Services\StripeService;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;

final readonly class StartBookingCheckoutAction
{
    public const string SESSION_KEY = 'booking_hold';

    public function __construct(
        private StripeService $stripe,
        private ShopService $shop,
        private BookingService $bookings,
        private ReleaseOrderAction $release,
    ) {}

    /**
     * @throws ApiErrorException
     */
    public function handle(Booking $booking): string
    {
        $order = $booking->order;

        abort_if($order === null, 404);

        $calculatesTax = $this->shop->calculatesTax();
        $when = $this->bookings->formatTime($booking->starts_at, $booking->timezone, $booking->locale);

        try {
            $session = $this->stripe->createCheckoutSession(array_filter([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => array_filter([
                        'currency' => Str::lower($order->currency),
                        'unit_amount' => $order->total_amount,
                        'product_data' => ['name' => $booking->service_name.' · '.$when, 'metadata' => ['record_id' => (string) $booking->record_id]],
                        'tax_behavior' => $calculatesTax ? ($this->shop->pricesIncludeTax() ? 'inclusive' : 'exclusive') : null,
                    ]),
                ]],
                'success_url' => $this->bookings->manageUrl($booking).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->bookings->manageUrl($booking, ['cancelled' => '1']),
                'client_reference_id' => (string) $order->id,
                'customer_email' => $booking->email,
                'metadata' => ['order_id' => (string) $order->id, 'order_reference' => $order->reference, 'booking_reference' => $booking->reference],
                'expires_at' => $order->expires_at?->getTimestamp(),
                'automatic_tax' => $calculatesTax ? ['enabled' => true] : null,
                'locale' => 'auto',
            ]));
        } catch (ApiErrorException $exception) {
            $this->release->handle($order);

            throw $exception;
        }

        $order->update(['stripe_session_id' => $session['id']]);

        session([self::SESSION_KEY => $booking->id]);

        return $session['url'];
    }
}
