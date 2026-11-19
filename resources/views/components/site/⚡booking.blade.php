<?php

declare(strict_types=1);

use App\Actions\CancelOrderAction;
use App\Actions\CreateBookingAction;
use App\Actions\StartBookingCheckoutAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Record;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Impersonation;
use App\Services\PageCache;
use App\Services\SettingsService;
use App\Traits\PicksBookingSlot;
use App\Traits\VerifiesTurnstile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

return new class extends Component
{
    use PicksBookingSlot, VerifiesTurnstile;

    private const int MIN_SECONDS = 3;

    private const int MAX_ATTEMPTS = 5;

    private const int MAX_BOOKINGS_PER_EMAIL = 3;

    private const int MAX_FREE_BOOKINGS_PER_ADDRESS = 10;

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
            $this->moveToOpenMonth();
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
        if ($this->services()->doesntContain('id', $id)) {
            return;
        }

        $this->serviceId = $id;
        $this->step = 'time';
        $this->date = '';
        $this->slot = '';
        $this->month = $this->clampMonth('');
        $this->moveToOpenMonth();
    }

    public function toConfirm(): void
    {
        $this->validate($this->detailRules(), [], $this->detailAttributes());

        if (! $this->chosenStart() instanceof CarbonImmutable) {
            $this->step = 'time';
            $this->addError('time', __('Pick a time first.'));

            return;
        }

        $this->step = 'confirm';
    }

    public function goTo(string $step): void
    {
        $order = ['service', 'time', 'details', 'confirm'];
        $target = array_search($step, $order, true);

        if ($target === false || $target >= array_search($this->step, $order, true) || ($step === 'service' && $this->services()->count() < 2)) {
            return;
        }

        $this->step = $step;
    }

    public function book(CreateBookingAction $create, StartBookingCheckoutAction $checkout, CancelOrderAction $cancelOrder): void
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

        $validated = $this->validate($this->detailRules(), [], $this->detailAttributes());

        $service = $this->service();
        $start = $this->chosenStart();

        if (! $service instanceof Record || ! $start instanceof CarbonImmutable) {
            $this->step = 'time';
            $this->slot = '';
            $this->addError('time', __('That time is no longer available.'));

            return;
        }

        $chargesNow = $this->chargesNow();
        $emailKey = 'booking-email:'.hash('xxh128', mb_strtolower($validated['email']));
        $addressKey = 'booking-address:'.request()->ip();

        if (! $chargesNow && RateLimiter::tooManyAttempts($emailKey, self::MAX_BOOKINGS_PER_EMAIL)) {
            $this->addError('form', __('This email address has made several bookings in the last hour. Please try again later.'));

            return;
        }

        if (! $chargesNow && RateLimiter::tooManyAttempts($addressKey, self::MAX_FREE_BOOKINGS_PER_ADDRESS)) {
            $this->addError('form', __('Too many bookings were made from here in the last hour. Please try again later.'));

            return;
        }

        if ($chargesNow) {
            $this->releasePreviousHold($cancelOrder);
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
            try {
                $this->redirect($checkout->handle($booking));
            } catch (ApiErrorException $exception) {
                report($exception);

                $this->step = 'time';
                $this->slot = '';
                $this->addError('form', __('Payment is not available right now. Please try again later.'));
            }

            return;
        }

        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($addressKey, 3600);

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
        return __(':count min', ['count' => resolve(BookingService::class)->duration($service)]);
    }

    public function chargesNow(): bool
    {
        return $this->service() instanceof Record && resolve(BookingService::class)->chargesOnBooking($this->service());
    }

    public function centered(): bool
    {
        return ($this->config['align'] ?? 'left') === 'center';
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

    /**
     * @return array<string, list<string>>
     */
    protected function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => $this->phoneMode() === 'required' ? ['required', 'string', 'max:40'] : ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function detailAttributes(): array
    {
        return ['name' => __('name'), 'email' => __('email'), 'phone' => __('phone'), 'notes' => __('notes')];
    }

    protected function slotPicked(): void
    {
        if ($this->step === 'time') {
            $this->step = 'details';
        }
    }

    protected function slotDuration(): int
    {
        return $this->service() instanceof Record ? resolve(BookingService::class)->duration($this->service()) : 0;
    }

    protected function slotMinimumNotice(): int
    {
        return $this->chargesNow() ? BookingService::HOLD_MINUTES : 0;
    }

    private function releasePreviousHold(CancelOrderAction $cancelOrder): void
    {
        $previous = Booking::query()->where('status', BookingStatus::PENDING_PAYMENT)->find(session()->pull(StartBookingCheckoutAction::SESSION_KEY));

        if (! $previous instanceof Booking || ! $previous->order instanceof Order) {
            return;
        }

        try {
            $cancelOrder->handle($previous->order);
        } catch (ApiErrorException $exception) {
            report($exception);
        }
    }
};
?>

@php
    $inputClass = 'wire-field w-full min-w-0 rounded-(--wire-btn-radius) bg-(--wire-input-bg) px-4 py-3 text-base text-(--wire-input-text) focus:outline-none';
    $errorClass = 'inline-block rounded-(--wire-radius) bg-red-100 px-2 py-0.5 text-sm font-medium text-red-700';
    $primaryClass = 'wire-btn inline-flex cursor-pointer items-center justify-center rounded-(--wire-btn-radius) bg-(--wire-primary-bg) px-6 py-3 text-base font-medium text-(--wire-primary-text) transition [--wire-btn-border:var(--wire-primary-border)] hover:opacity-90 disabled:opacity-50';
    $stepTitleClass = 'mb-4 text-lg font-medium';
    $recapClass = 'mb-6 flex flex-wrap items-baseline justify-between gap-2 border-b border-current/10 pb-4 text-sm';
    $linkClass = 'cursor-pointer text-(--wire-accent) underline';
    $panelClass = 'wire-card rounded-(--wire-radius) bg-(--wire-card-bg) p-6 text-(--wire-card-text)';
    $heading = $this->text('heading');
    $intro = $this->text('intro');
    $showPrices = ($this->config['showPrices'] ?? true) !== false;
    $service = $this->service();
    $headingLevel = \App\Services\BlockHeading::level($this->config['headingLevel'] ?? null);
    $bookings = resolve(\App\Services\BookingService::class);
@endphp

<div
    class="space-y-12"
    data-booking
    x-data
    x-init="$wire.timezone === @js($bookings->timezone()) && Intl.DateTimeFormat().resolvedOptions().timeZone !== $wire.timezone && $wire.useTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone)"
>
    @if ($heading !== '' || $intro !== '')
        <div @class(['space-y-4', 'text-center' => $this->centered()])>
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
        </div>
    @endif

    <div class="mx-auto max-w-2xl space-y-12">
        @error('form')
            <p class="{{ $errorClass }}">{{ $message }}</p>
        @enderror

        @if ($this->services()->isEmpty())
            <p class="opacity-70">{{ __('There is nothing to book at the moment.') }}</p>
        @elseif ($step === 'done')
            <div class="{{ $panelClass }} space-y-3" data-booking-done>
                <div class="[&>p]:m-0 [&_a]:text-(--wire-accent) [&_a]:underline leading-(--wire-body-leading)">
                    {!! $this->text('successMessage', '<p>'.e(__('You are booked in. We have sent the details to your email.')).'</p>') !!}
                </div>
                <p class="font-medium">{{ $bookedWhen }}</p>
                <a
                    href="{{ $manageUrl }}"
                    class="text-(--wire-accent) underline"
                >{{ __('Change or cancel this booking') }}</a>
            </div>
        @else
            @if ($step === 'service' || ! $service)
                <section data-booking-step="service">
                    <h3 class="{{ $stepTitleClass }}">{{ __('Choose a service') }}</h3>
                    <div class="[&:has(>button:hover)>button:not(:hover)]:opacity-50 -my-1.5" data-booking-services>
                        @foreach ($this->services() as $option)
                            <button
                                type="button"
                                wire:key="service-{{ $option->id }}"
                                wire:click="chooseService({{ $option->id }})"
                                class="block w-full cursor-pointer py-1.5 text-left transition-opacity duration-200"
                            >
                                <span @class([
                                    'wire-card grid gap-2 rounded-(--wire-radius) bg-(--wire-card-bg) p-5 text-(--wire-card-text) sm:grid-cols-[1fr_1.4fr] sm:gap-6',
                                    'ring-2 ring-(--wire-primary-bg)' => $serviceId === $option->id,
                                ])>
                                    <span>
                                        <span class="block text-lg font-medium">{{ $option->displayHeading() }}</span>
                                        <span class="block text-sm opacity-70">
                                            {{ $this->durationLabel($option) }}
                                            @if ($showPrices && $this->priceLabel($option) !== '')
                                                · {{ $this->priceLabel($option) }}
                                            @endif
                                        </span>
                                    </span>
                                    @if ($option->displayExcerpt() !== '')
                                        <span class="text-sm leading-(--wire-body-leading) opacity-80">{{ $option->displayExcerpt() }}</span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @elseif ($step === 'time')
                <section data-booking-step="time">
                    <div class="{{ $recapClass }}">
                        <span>{{ $service->displayHeading() }} · {{ $this->durationLabel($service) }}</span>
                        @if ($this->services()->count() > 1)
                            <button type="button" wire:click="goTo('service')" class="{{ $linkClass }}">
                                {{ __('Change') }}
                            </button>
                        @endif
                    </div>
                    <x-site.booking-calendar
                        :title="__('Choose a date and time')"
                        :month-label="$this->monthLabel()"
                        :can-go-back="$this->canGoBack()"
                        :can-go-forward="$this->canGoForward()"
                        :days="$this->availableDays()"
                        :date="$this->activeDate()"
                        :times="$this->slotsForDate()"
                        :selected="$this->slot"
                        :timezone="$timezone"
                        :timezones="$this->timezoneOptions()"
                    />
                    @error('time')
                        <p class="{{ $errorClass }} mt-3">{{ $message }}</p>
                    @enderror
                </section>
            @elseif ($step === 'details')
                <section data-booking-step="details">
                    <div class="{{ $recapClass }}">
                        <span>{{ $service->displayHeading() }} · {{ $this->chosenLabel() }}</span>
                        <button type="button" wire:click="goTo('time')" class="{{ $linkClass }}">
                            {{ __('Change') }}
                        </button>
                    </div>
                    <h3 class="{{ $stepTitleClass }}">{{ __('Your information') }}</h3>
                    <div class="{{ $panelClass }} grid gap-4 sm:grid-cols-2">
                        <div class="space-y-4">
                            <div>
                                <label
                                    for="booking-name-{{ $this->getId() }}"
                                    class="mb-1 block text-sm"
                                >{{ __('Name') }}</label>
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
                                <label
                                    for="booking-email-{{ $this->getId() }}"
                                    class="mb-1 block text-sm"
                                >{{ __('Email') }}</label>
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
                        </div>
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-1 flex-col">
                                <label
                                    for="booking-notes-{{ $this->getId() }}"
                                    class="mb-1 block text-sm"
                                >{{ __('Anything we should know? (optional)') }}</label>
                                <textarea
                                    id="booking-notes-{{ $this->getId() }}"
                                    wire:model="notes"
                                    rows="5"
                                    class="{{ $inputClass }} flex-1 rounded-[min(var(--wire-btn-radius),1.25rem)]"
                                ></textarea>
                                @error('notes')
                                    <p class="{{ $errorClass }} mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <button type="button" wire:click="toConfirm" class="{{ $primaryClass }} w-full">
                                {{ __('Next') }}
                            </button>
                        </div>
                    </div>
                </section>
            @else
                <section data-booking-step="confirm">
                    <div class="{{ $recapClass }}">
                        <span>{{ __('Check the details, then book.') }}</span>
                        <button type="button" wire:click="goTo('details')" class="{{ $linkClass }}">
                            {{ __('Change details') }}
                        </button>
                    </div>
                    <h3 class="{{ $stepTitleClass }}">{{ __('Confirm your booking') }}</h3>
                    <form wire:submit="book" novalidate class="{{ $panelClass }} space-y-6">
                        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-[9rem_1fr]">
                            <dt class="text-sm opacity-70">{{ __('Service') }}</dt>
                            <dd>{{ $service?->displayHeading() }}</dd>
                            <dt class="text-sm opacity-70">{{ __('Date and time') }}</dt>
                            <dd>{{ $this->chosenLabel() }}</dd>
                            @if ($service && $bookings->location($service) !== '')
                                <dt class="text-sm opacity-70">{{ __('Where') }}</dt>
                                <dd>{{ $bookings->location($service) }}</dd>
                            @endif
                            @if ($service && $showPrices && $this->priceLabel($service) !== '')
                                <dt class="text-sm opacity-70">{{ __('Price') }}</dt>
                                <dd>{{ $this->priceLabel($service) }}</dd>
                            @endif
                            <dt class="text-sm opacity-70">{{ __('Name') }}</dt>
                            <dd>{{ $name }}</dd>
                            <dt class="text-sm opacity-70">{{ __('Email') }}</dt>
                            <dd>{{ $email }}</dd>
                            @if ($phone !== '')
                                <dt class="text-sm opacity-70">{{ __('Phone') }}</dt>
                                <dd>{{ $phone }}</dd>
                            @endif
                            @if ($notes !== '')
                                <dt class="text-sm opacity-70">{{ __('Note') }}</dt>
                                <dd class="whitespace-pre-line">{{ $notes }}</dd>
                            @endif
                        </dl>

                        <div class="absolute -left-[9999px]" aria-hidden="true">
                            <label
                                >{{ __('Leave this field empty') }}
                                <input type="text" wire:model="website" tabindex="-1" autocomplete="off"
                            /></label>
                        </div>

                        <x-site.turnstile error="form" />

                        <button type="submit" class="{{ $primaryClass }} w-full" wire:loading.attr="disabled">
                            @if ($this->chargesNow())
                                {{ $this->text('submitText', __('Continue to payment')) }} · {{ $this->priceLabel($service) }}
                            @else
                                {{ $this->text('submitText', __('Book now')) }}
                            @endif
                        </button>
                    </form>
                </section>
            @endif
        @endif
    </div>
</div>
