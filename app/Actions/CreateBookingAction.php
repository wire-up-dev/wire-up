<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEvent;
use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\WebhookEvent;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Record;
use App\Models\User;
use App\Notifications\BookingAlert;
use App\Notifications\BookingUpdate;
use App\Services\ActivityLog;
use App\Services\BookingAvailability;
use App\Services\BookingMessages;
use App\Services\BookingService;
use App\Services\SettingsService;
use App\Services\ShopService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateBookingAction
{
    public function __construct(
        private BookingService $bookings,
        private BookingAvailability $availability,
        private ClaimBookingTimeAction $claim,
        private BookingMessages $messages,
    ) {}

    /**
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, notes?: ?string, locale?: string, timezone?: string, service_name?: string, duration?: int}  $details
     */
    public function handle(?Record $service, CarbonImmutable $start, array $details, ?User $user = null, BookingActor $source = BookingActor::CUSTOMER): Booking
    {
        $byOwner = $source === BookingActor::OWNER;
        $duration = $service instanceof Record ? $this->bookings->duration($service) : (int) ($details['duration'] ?? 0);
        $charges = ! $byOwner && $service instanceof Record && $this->bookings->chargesOnBooking($service);

        throw_if($duration <= 0 || $start->minute % 5 !== 0, ValidationException::withMessages(['time' => __('That time is no longer available.')]));

        throw_if(
            ! $byOwner && ! $this->availability->isOffered($duration, $start, minimumNotice: $charges ? BookingService::HOLD_MINUTES : 0),
            ValidationException::withMessages(['time' => __('That time is no longer available.')]),
        );

        $booking = DB::transaction(function () use ($service, $start, $details, $user, $source, $duration, $charges): Booking {
            $booking = Booking::query()->create([
                'reference' => Booking::newReference(),
                'manage_token' => Booking::newManageToken(),
                'status' => $charges ? BookingStatus::PENDING_PAYMENT : BookingStatus::CONFIRMED,
                'source' => $source,
                'record_id' => $service?->id,
                'service_name' => $service?->displayHeading() ?? ($details['service_name'] ?? __('Busy')),
                'duration_minutes' => $duration,
                'buffer_minutes' => $this->bookings->buffer(),
                'starts_at' => $start->utc(),
                'ends_at' => $start->utc()->addMinutes($duration),
                'name' => $details['name'] ?? null,
                'email' => isset($details['email']) && $details['email'] !== '' ? mb_strtolower($details['email']) : null,
                'phone' => $details['phone'] ?? null,
                'notes' => $details['notes'] ?? null,
                'locale' => $details['locale'] ?? app()->getLocale(),
                'timezone' => $details['timezone'] ?? $this->bookings->timezone(),
                'user_id' => $user?->id,
                'confirmed_at' => $charges ? null : now(),
                'reminded_at' => $this->remindedAt($start),
            ]);

            $this->claim->handle($booking);

            if ($charges) {
                $booking->update(['order_id' => $this->createOrder($booking, $service)->id]);
            }

            return $booking;
        });

        if ($booking->status === BookingStatus::CONFIRMED) {
            $this->messages->customer($booking, BookingUpdate::CONFIRMED);
            $this->messages->webhook($booking, WebhookEvent::BOOKING_CONFIRMED);

            if ($byOwner) {
                ActivityLog::record(ActivityEvent::BOOKING_CREATED, $booking);
            } else {
                $this->messages->owner($booking, BookingAlert::NEW);
            }
        }

        return $booking;
    }

    private function remindedAt(CarbonImmutable $start): ?CarbonImmutable
    {
        $hours = $this->bookings->reminderHours();

        return $hours === 0 || $start->lessThanOrEqualTo(now()->addHours($hours)) ? CarbonImmutable::now() : null;
    }

    private function createOrder(Booking $booking, Record $service): Order
    {
        $amount = resolve(ShopService::class)->toMinor((string) $this->bookings->price($service));

        $order = Order::query()->create([
            'reference' => Order::newReference(),
            'status' => OrderStatus::PENDING,
            'user_id' => $booking->user_id,
            'email' => $booking->email,
            'name' => $booking->name,
            'currency' => SettingsService::current()->currency(),
            'subtotal_amount' => $amount,
            'total_amount' => $amount,
            'locale' => $booking->locale,
            'expires_at' => now()->addMinutes(BookingService::HOLD_MINUTES),
        ]);

        $order->items()->create([
            'record_id' => $service->id,
            'name' => $booking->service_name,
            'sku' => null,
            'unit_amount' => $amount,
            'quantity' => 1,
            'line_amount' => $amount,
            'reserved_stock' => 0,
        ]);

        return $order;
    }
}
