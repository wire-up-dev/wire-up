<?php

declare(strict_types=1);

use App\Actions\CreateBookingAction;
use App\Actions\StartBookingCheckoutAction;
use App\Enums\BookingStatus;
use App\Models\Record;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Impersonation;
use App\Services\PageCache;
use App\Services\SettingsService;
use App\Traits\PicksBookingSlot;
use App\Traits\VerifiesTurnstile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

return new class extends Component
{
    use PicksBookingSlot, VerifiesTurnstile;

    private const int MIN_SECONDS = 3;

    private const int MAX_ATTEMPTS = 5;

    /** @var array<string, mixed> */
    #[Locked]
    public array $config = [];

    #[Locked]
    public ?int $blockId = null;

    #[Locked]
    public int $startedAt = 0;

    public ?int $serviceId = null;

    public string $step = 'service';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public string $website = '';

    public string $manageUrl = '';

    public string $bookedWhen = '';

    /**
     * @var Collection<int, Record>|null
     */
    private ?Collection $serviceList = null;

    public function mount(): void
    {
        resolve(PageCache::class)->skip();

        $this->startedAt = now()->timestamp;
        $this->initSlotPicker();

        $user = auth()->user();

        if ($user instanceof User) {
            $this->name = $user->name;
            $this->email = $user->email;
        }

        if ($this->services()->count() === 1) {
            $this->serviceId = $this->services()->first()?->id;
            $this->step = 'time';
        }
    }

    /**
     * @return Collection<int, Record>
     */
    public function services(): Collection
    {
        $ids = $this->config['recordIds'] ?? [];

        return $this->serviceList ??= resolve(BookingService::class)->services(is_array($ids) ? array_filter($ids, fn (mixed $id): bool => is_int($id) || is_string($id)) : []);
    }

    public function service(): ?Record
    {
        return $this->services()->firstWhere('id', $this->serviceId);
    }

    public function chooseService(int $id): void
    {
        if ($this->services()->contains('id', $id)) {
            $this->serviceId = $id;
            $this->step = 'time';
            $this->date = '';
            $this->slot = '';
        }
    }

    public function toDetails(): void
    {
        if (! $this->chosenStart() instanceof \Carbon\CarbonImmutable) {
            $this->addError('time', __('Pick a time first.'));

            return;
        }

        $this->step = 'details';
    }

    public function back(): void
    {
        $this->step = match ($this->step) {
            'details' => 'time',
            default => $this->services()->count() > 1 ? 'service' : 'time',
        };
    }

    public function book(CreateBookingAction $create, StartBookingCheckoutAction $checkout): void
    {
        if ($this->website !== '' || now()->timestamp - $this->startedAt < self::MIN_SECONDS || Impersonation::active()) {
            $this->addError('form', __('Sorry, your booking could not be made. Please try again.'));

            return;
        }

        $throttleKey = 'booking:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $this->addError('form', __('Too many attempts. Please wait a moment and try again.'));

            return;
        }

        RateLimiter::hit($throttleKey, 600);

        $this->ensureHuman('form');

        $phone = $this->phoneMode();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => $phone === 'required' ? ['required', 'string', 'max:40'] : ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'name' => __('name'),
            'email' => __('email'),
            'phone' => __('phone'),
            'notes' => __('notes'),
        ]);

        $service = $this->service();
        $start = $this->chosenStart();

        if (! $service instanceof Record || ! $start instanceof \Carbon\CarbonImmutable) {
            $this->step = 'time';
            $this->slot = '';
            $this->addError('time', __('That time is no longer available.'));

            return;
        }

        try {
            $booking = $create->handle($service, $start, [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $phone === 'off' ? null : ($validated['phone'] ?: null),
                'notes' => $validated['notes'] ?: null,
                'locale' => app()->getLocale(),
                'timezone' => $this->timezone,
            ], auth()->user() instanceof User ? auth()->user() : null);
        } catch (ValidationException $exception) {
            $this->step = 'time';
            $this->slot = '';
            $this->addError('time', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        if ($booking->status === BookingStatus::PENDING_PAYMENT) {
            $this->redirect($checkout->handle($booking));

            return;
        }

        $this->bookedWhen = $this->chosenLabel();
        $this->manageUrl = resolve(BookingService::class)->manageUrl($booking);
        $this->step = 'done';
    }

    public function priceLabel(Record $service): string
    {
        $price = resolve(BookingService::class)->price($service);

        return $price === null ? '' : SettingsService::current()->formatMoney($price);
    }

    public function durationLabel(Record $service): string
    {
        return trans_choice(':count minute|:count minutes', resolve(BookingService::class)->duration($service));
    }

    public function chargesNow(): bool
    {
        return $this->service() instanceof Record && resolve(BookingService::class)->chargesOnBooking($this->service());
    }

    public function phoneMode(): string
    {
        $mode = $this->config['phone'] ?? 'optional';

        return in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'optional';
    }

    public function text(string $key, string $default = ''): string
    {
        $map = data_get($this->config, $key);
        $map = is_array($map) ? $map : [];
        $value = $map[app()->getLocale()] ?? $map[config()->string('app.default_locale', 'en')] ?? '';

        return is_string($value) && strip_tags($value) !== '' ? $value : $default;
    }

    protected function slotDuration(): int
    {
        return $this->service() instanceof Record ? resolve(BookingService::class)->duration($this->service()) : 0;
    }

    protected function slotMinimumNotice(): int
    {
        return $this->chargesNow() ? BookingService::HOLD_MINUTES : 0;
    }
};
?>

@php
    $inputClass = 'wire-field w-full min-w-0 rounded-(--wire-btn-radius) bg-(--wire-input-bg) px-4 py-3 text-base text-(--wire-input-text) focus:outline-none';
    $errorClass = 'inline-block rounded-(--wire-radius) bg-red-100 px-2 py-0.5 text-sm font-medium text-red-700';
    $primaryClass = 'wire-btn inline-flex items-center justify-center rounded-(--wire-btn-radius) bg-(--wire-primary-bg) px-6 py-3 text-base font-medium text-(--wire-primary-text) transition [--wire-btn-border:var(--wire-primary-border)] hover:opacity-90 disabled:opacity-50';
    $heading = $this->text('heading');
    $intro = $this->text('intro');
    $showPrices = ($this->config['showPrices'] ?? true) !== false;
    $service = $this->service();
    $headingLevel = \App\Services\BlockHeading::level($this->config['headingLevel'] ?? null);
@endphp

<div
    class="space-y-8"
    data-booking
    x-data
    x-init="$wire.timezone === @js(resolve(\App\Services\BookingService::class)->timezone()) && Intl.DateTimeFormat().resolvedOptions().timeZone !== $wire.timezone && $wire.useTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone)"
>
    @if ($heading !== '')
        <div class="[&>p]:m-0 [&_a]:text-(--wire-accent) [&_a]:underline text-(length:--wire-heading-size) tracking-tight text-(--wire-heading)">
            <x-site.blocks.heading :html="$heading" :level="$headingLevel" />
        </div>
    @endif

    @if ($intro !== '')
        <div class="[&>p]:m-0 [&_a]:text-(--wire-accent) [&_a]:underline leading-(--wire-body-leading)">
            {!! $intro !!}
        </div>
    @endif

    @error('form')
        <p class="{{ $errorClass }}">{{ $message }}</p>
    @enderror

    @if ($this->services()->isEmpty())
        <p class="opacity-70">{{ __('There is nothing to book at the moment.') }}</p>
    @elseif ($step === 'done')
        <div
            class="wire-field space-y-3 rounded-(--wire-radius) bg-(--wire-input-bg) p-6 text-(--wire-input-text)"
            data-booking-done
        >
            <div class="[&>p]:m-0 [&_a]:text-(--wire-accent) [&_a]:underline leading-(--wire-body-leading)">
                {!! $this->text('successMessage', '<p>'.e(__('You are booked in. We have sent the details to your email.')).'</p>') !!}
            </div>
            <p class="font-medium">{{ $bookedWhen }}</p>
            <a
                href="{{ $manageUrl }}"
                class="text-(--wire-accent) underline"
            >{{ __('Change or cancel this booking') }}</a>
        </div>
    @elseif ($step === 'service' || ! $service)
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($this->services() as $option)
                <button
                    type="button"
                    wire:key="service-{{ $option->id }}"
                    wire:click="chooseService({{ $option->id }})"
                    class="wire-card flex flex-col items-start gap-2 rounded-(--wire-radius) bg-(--wire-card-bg) p-5 text-left text-(--wire-card-text) transition hover:opacity-90"
                >
                    <span class="text-lg font-medium">{{ $option->displayHeading() }}</span>
                    <span class="text-sm opacity-70">
                        {{ $this->durationLabel($option) }}
                        @if ($showPrices && $this->priceLabel($option) !== '')
                            · {{ $this->priceLabel($option) }}
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    @elseif ($step === 'time')
        <div class="space-y-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-lg font-medium">
                    {{ $service->displayHeading() }}
                    <span class="text-base font-normal opacity-70">· {{ $this->durationLabel($service) }}</span>
                </p>
                @if ($this->services()->count() > 1)
                    <button type="button" wire:click="back" class="text-sm text-(--wire-accent) underline">
                        {{ __('Choose another service') }}
                    </button>
                @endif
            </div>

            <x-site.booking-calendar
                :days="$this->calendarDays()"
                :weekdays="$this->weekdayNames()"
                :month-label="$this->monthLabel()"
                :can-go-back="$this->canGoBack()"
                :can-go-forward="$this->canGoForward()"
                :date="$date"
                :times="$this->slotsForDate()"
                :slot="$slot"
                :timezone="$timezone"
                :timezones="$this->timezoneOptions()"
            />

            @error('time')
                <p class="{{ $errorClass }}">{{ $message }}</p>
            @enderror

            <button type="button" wire:click="toDetails" class="{{ $primaryClass }}" @disabled($slot === '')>
                {{ __('Continue') }}
            </button>
        </div>
    @else
        <form wire:submit="book" novalidate class="max-w-xl space-y-4">
            <p class="font-medium">
                {{ $service->displayHeading() }} · {{ $this->chosenLabel() }}
                <button type="button" wire:click="back" class="ml-2 text-sm font-normal text-(--wire-accent) underline">
                    {{ __('Change time') }}
                </button>
            </p>

            <div>
                <label for="booking-name-{{ $this->getId() }}" class="mb-1 block text-sm">{{ __('Name') }}</label>
                <input
                    id="booking-name-{{ $this->getId() }}"
                    type="text"
                    wire:model="name"
                    autocomplete="name"
                    class="{{ $inputClass }}"
                />
                @error('name')
                    <p class="{{ $errorClass }} mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="booking-email-{{ $this->getId() }}" class="mb-1 block text-sm">{{ __('Email') }}</label>
                <input
                    id="booking-email-{{ $this->getId() }}"
                    type="email"
                    wire:model="email"
                    autocomplete="email"
                    class="{{ $inputClass }}"
                />
                @error('email')
                    <p class="{{ $errorClass }} mt-1">{{ $message }}</p>
                @enderror
            </div>

            @if ($this->phoneMode() !== 'off')
                <div>
                    <label
                        for="booking-phone-{{ $this->getId() }}"
                        class="mb-1 block text-sm"
                    >{{ $this->phoneMode() === 'required' ? __('Phone') : __('Phone (optional)') }}</label>
                    <input
                        id="booking-phone-{{ $this->getId() }}"
                        type="tel"
                        wire:model="phone"
                        autocomplete="tel"
                        class="{{ $inputClass }}"
                    />
                    @error('phone')
                        <p class="{{ $errorClass }} mt-1">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div>
                <label
                    for="booking-notes-{{ $this->getId() }}"
                    class="mb-1 block text-sm"
                >{{ __('Anything we should know? (optional)') }}</label>
                <textarea
                    id="booking-notes-{{ $this->getId() }}"
                    wire:model="notes"
                    rows="4"
                    class="{{ $inputClass }} rounded-[min(var(--wire-btn-radius),1.25rem)]"
                ></textarea>
                @error('notes')
                    <p class="{{ $errorClass }} mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label
                    >{{ __('Leave this field empty') }}
                    <input type="text" wire:model="website" tabindex="-1" autocomplete="off"
                /></label>
            </div>

            <x-site.turnstile error="form" />

            <button type="submit" class="{{ $primaryClass }}" wire:loading.attr="disabled">
                @if ($this->chargesNow())
                    {{ $this->text('submitText', __('Continue to payment')) }} · {{ $this->priceLabel($service) }}
                @else
                    {{ $this->text('submitText', __('Book now')) }}
                @endif
            </button>
        </form>
    @endif
</div>
