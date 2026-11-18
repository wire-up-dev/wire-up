<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;

final readonly class BookingCalendarFile
{
    public function forCustomer(Booking $booking, string $location = ''): string
    {
        $brand = SettingsService::current()->brandName();

        return $this->calendar(
            $booking->status === BookingStatus::CANCELLED ? 'CANCEL' : 'PUBLISH',
            [$this->event($booking, $booking->service_name.' · '.$brand, '', $location)],
        );
    }

    /**
     * @param  iterable<Booking>  $bookings
     */
    public function feed(iterable $bookings): string
    {
        $events = [];

        foreach ($bookings as $booking) {
            $details = array_filter([$booking->name, $booking->email, $booking->phone, $booking->notes]);
            $events[] = $this->event($booking, $booking->service_name.($booking->name ? ' · '.$booking->name : ''), implode("\n", $details), '');
        }

        return $this->calendar('PUBLISH', $events, SettingsService::current()->brandName());
    }

    /**
     * @param  list<list<string>>  $events
     */
    private function calendar(string $method, array $events, string $name = ''): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Wire Up//Bookings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            ...($name !== '' ? ['X-WR-CALNAME:'.$this->escape($name)] : []),
            ...array_merge([], ...$events),
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * @return list<string>
     */
    private function event(Booking $booking, string $summary, string $description, string $location): array
    {
        return [
            'BEGIN:VEVENT',
            'UID:booking-'.$booking->reference.'@'.(parse_url(config()->string('app.url'), PHP_URL_HOST) ?: 'wire-up'),
            'DTSTAMP:'.$this->stamp(CarbonImmutable::now()),
            'DTSTART:'.$this->stamp($booking->starts_at),
            'DTEND:'.$this->stamp($booking->ends_at),
            'SEQUENCE:'.$booking->sequence,
            'STATUS:'.($booking->status === BookingStatus::CANCELLED ? 'CANCELLED' : 'CONFIRMED'),
            'SUMMARY:'.$this->escape($summary),
            ...($description !== '' ? ['DESCRIPTION:'.$this->escape($description)] : []),
            ...($location !== '' ? ['LOCATION:'.$this->escape($location)] : []),
            'END:VEVENT',
        ];
    }

    private function stamp(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    private function fold(string $line): string
    {
        if (mb_strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $limit = 75;

        while (mb_strlen($line) > $limit) {
            $chunk = mb_strcut($line, 0, $limit, 'UTF-8');
            $parts[] = $chunk;
            $line = mb_substr($line, mb_strlen($chunk));
            $limit = 74;
        }

        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
