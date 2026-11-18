<?php

declare(strict_types=1);

use App\Actions\CancelBookingAction;
use App\Actions\CreateBookingAction;
use App\Actions\DeleteBookingAction;
use App\Enums\BookingActor;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Record;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Stripe\Exception\ApiErrorException;

return new class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'when', except: 'upcoming')]
    public string $when = 'upcoming';

    public string $serviceId = '';

    public string $day = '';

    public string $time = '';

    public int $duration = 60;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'when'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @throws ApiErrorException
     */
    public function cancel(int $id, CancelBookingAction $cancel): void
    {
        $this->authorize('bookings.edit');

        $booking = Booking::query()->find($id);

        if ($booking instanceof Booking && $cancel->handle($booking, BookingActor::OWNER)) {
            Flux::toast(__('Booking cancelled.'), variant: 'success');
            unset($this->bookings);
        }
    }

    public function delete(int $id, DeleteBookingAction $delete): void
    {
        $this->authorize('bookings.delete');

        $booking = Booking::query()->find($id);

        if ($booking instanceof Booking && $delete->handle($booking)) {
            Flux::toast(__('Booking deleted.'), variant: 'success');
            unset($this->bookings);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'when']);
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Booking> */
    #[Computed]
    public function bookings(): LengthAwarePaginator
    {
        $status = BookingStatus::tryFrom($this->status);
        $upcoming = $this->when !== 'past';

        return Booking::query()
            ->when($status, fn (Builder $query, BookingStatus $status): Builder => $query->where('status', $status))
            ->when($this->search, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $inner): Builder => $inner->whereLike('reference', "%{$search}%")->orWhereLike('email', "%{$search}%")->orWhereLike('name', "%{$search}%")->orWhereLike('service_name', "%{$search}%")))
            ->where('ends_at', $upcoming ? '>=' : '<', now())
            ->orderBy('starts_at', $upcoming ? 'asc' : 'desc')
            ->paginate(20);
    }

    /** @return Collection<int, Record> */
    #[Computed]
    public function services(): Collection
    {
        return Record::query()
            ->with('translations')
            ->whereHas('recordType', fn ($query) => $query->where('bookable', true))
            ->orderBy('id')
            ->get();
    }

    public function openAdd(): void
    {
        $this->authorize('bookings.edit');

        $this->reset(['serviceId', 'day', 'time', 'name', 'email', 'phone', 'notes']);
        $this->duration = 60;
        $this->resetValidation();

        Flux::modal('add-booking')->show();
    }

    public function add(CreateBookingAction $create, BookingService $bookings): void
    {
        $this->authorize('bookings.edit');

        $validated = $this->validate([
            'serviceId' => ['nullable', 'integer'],
            'day' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['required_without:serviceId', 'integer', 'min:5', 'max:720', 'multiple_of:5'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'time.date_format' => __('Enter the time as hours and minutes, like 14:30.'),
            'duration.multiple_of' => __('Use steps of 5 minutes.'),
        ], [
            'day' => __('day'),
            'time' => __('time'),
            'duration' => __('length'),
        ]);

        $service = $validated['serviceId'] ? $this->services()->firstWhere('id', (int) $validated['serviceId']) : null;
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $validated['day'].' '.$validated['time'], $bookings->timezone());

        try {
            $create->handle($service, $start, [
                'name' => $validated['name'] ?: null,
                'email' => $validated['email'] ?: null,
                'phone' => $validated['phone'] ?: null,
                'notes' => $validated['notes'] ?: null,
                'locale' => resolve('localization')->getDefaultLocale(),
                'timezone' => $bookings->timezone(),
                'duration' => $validated['duration'],
            ], source: BookingActor::OWNER);
        } catch (ValidationException $exception) {
            $this->addError('time', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        Flux::modal('add-booking')->close();
        Flux::toast(__('Booking added.'), variant: 'success');
        unset($this->bookings);
    }

    public function render(): View
    {
        return $this->view()
            ->title(__('Bookings'))
            ->layout('layouts::admin');
    }
};
?>

@php
    $bookingService = resolve(\App\Services\BookingService::class);
    $siteZone = $bookingService->timezone();
@endphp

<div class="space-y-6">
    @if (! $bookingService->availabilityIsSet())
        <flux:callout icon="clock" variant="warning" data-availability-missing>
            <flux:callout.text>
                {{ __('Visitors can book once you set your hours and time zone.') }}
                @can('settings.view')
                    <flux:callout.link :href="route('admin.settings-bookings')" wire:navigate>
                        {{ __('Set availability') }}</flux:callout.link>
                @endcan
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        @can('bookings.edit')
            <flux:button
                variant="primary"
                class="shrink-0"
                size="sm"
                icon="plus"
                iconVariant="outline"
                wire:click="openAdd"
            >{{ __('Add') }}</flux:button>
        @endcan

        <flux:dropdown position="bottom" align="start">
            <flux:button class="shrink-0" size="sm" icon="funnel" iconVariant="outline">{{ __('Filter') }}</flux:button>

            <flux:menu>
                <flux:menu.submenu heading="{{ __('When') }}">
                    <flux:menu.radio.group wire:model.live="when" heading="{{ __('When') }}">
                        <flux:menu.radio value="upcoming">{{ __('Upcoming') }}</flux:menu.radio>
                        <flux:menu.radio value="past">{{ __('Past') }}</flux:menu.radio>
                    </flux:menu.radio.group>
                </flux:menu.submenu>
                <flux:menu.submenu heading="{{ __('Status') }}">
                    <flux:menu.radio.group wire:model.live="status" heading="{{ __('Status') }}">
                        <flux:menu.radio value="" checked>{{ __('All') }}</flux:menu.radio>
                        @foreach (\App\Enums\BookingStatus::cases() as $statusOption)
                            <flux:menu.radio value="{{ $statusOption->value }}">
                                {{ $statusOption->label() }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
                </flux:menu.submenu>
                @if ($status !== '' || $when === 'past')
                    <flux:menu.separator />
                    <flux:menu.item icon="x-mark" wire:click="clearFilters"> {{ __('Clear filters') }}</flux:menu.item>
                @endif
            </flux:menu>
        </flux:dropdown>
        @if ($when === 'past')
            <x-admin.filter-chip :label="__('Past')" wire:click="$set('when', 'upcoming')" />
        @endif
        @if (\App\Enums\BookingStatus::tryFrom($status))
            <x-admin.filter-chip
                :label="\App\Enums\BookingStatus::from($status)->label()"
                wire:click="$set('status', '')"
            />
        @endif

        <div class="ms-auto min-w-40 flex-1 sm:w-52 sm:flex-none">
            <flux:input
                icon="magnifying-glass"
                wire:model.live="search"
                size="sm"
                placeholder="{{ __('Search...') }}"
                clearable
            />
        </div>
        @can('settings.view')
            <flux:button
                class="shrink-0"
                size="sm"
                icon="clock"
                iconVariant="outline"
                :href="route('admin.settings-bookings')"
                wire:navigate
            >{{ __('Availability') }}</flux:button>
        @endcan
    </div>

    <flux:table :paginate="$this->bookings" class="md:w-full md:table-fixed">
        <flux:table.columns>
            <flux:table.column class="w-60">{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Who') }}</flux:table.column>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column class="w-40">{{ __('Status') }}</flux:table.column>
            <flux:table.column class="w-10"></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->bookings as $booking)
                <flux:table.row wire:key="booking-{{ $booking->id }}">
                    <flux:table.cell class="whitespace-nowrap">
                        <a
                            href="{{ route('admin.bookings-show', $booking) }}"
                            wire:navigate
                            class="font-medium hover:underline"
                        >
                            {{ $booking->starts_at->setTimezone($siteZone)->locale(app()->getLocale())->isoFormat('ddd D MMM YYYY, LT') }}</a>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="min-w-0">
                            <flux:text
                                variant="strong"
                                class="truncate"
                            >{{ $booking->name ?: __('Blocked time') }}</flux:text>
                            @if ($booking->email)
                                <flux:text class="truncate">{{ $booking->email }}</flux:text>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="truncate">{{ $booking->service_name }}</flux:table.cell>
                    <flux:table.cell>
                        <x-admin.status-badge :color="$booking->status->color()">
                            {{ $booking->status->label() }}</x-admin.status-badge>
                        @if ($booking->clashed)
                            <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __('Clash') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:dropdown class="flex justify-end">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="ellipsis-horizontal"
                                square
                                :aria-label="__('Actions')"
                            />
                            <flux:menu>
                                <flux:menu.item
                                    icon="eye"
                                    href="{{ route('admin.bookings-show', $booking) }}"
                                    wire:navigate
                                >
                                    {{ __('View') }}
                                </flux:menu.item>
                                @can('bookings.edit')
                                    @if ($booking->status === \App\Enums\BookingStatus::CONFIRMED)
                                        <flux:menu.item
                                            icon="arrows-right-left"
                                            href="{{ route('admin.bookings-show', ['booking' => $booking, 'move' => 1]) }}"
                                            wire:navigate
                                        >
                                            {{ __('Move…') }}
                                        </flux:menu.item>
                                        @if ($booking->ends_at->isFuture())
                                            <flux:menu.separator />
                                            <flux:menu.item
                                                icon="x-circle"
                                                variant="danger"
                                                wire:click="cancel({{ $booking->id }})"
                                                wire:confirm="{{ __('Cancel this booking? The time becomes free again.') }}"
                                            >
                                                {{ __('Cancel') }}
                                            </flux:menu.item>
                                        @endif
                                    @endif
                                @endcan
                                @can('bookings.delete')
                                    @if ($booking->canBeDeleted())
                                        <flux:menu.separator />
                                        <flux:menu.item
                                            icon="trash"
                                            variant="danger"
                                            wire:click="delete({{ $booking->id }})"
                                            wire:confirm="{{ __('Delete this booking? This cannot be undone.') }}"
                                        >
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                    @endif
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-10 text-center">
                        <flux:text>{{ $when === 'past' ? __('No past bookings yet.') : __('No upcoming bookings.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="add-booking" class="w-full md:max-w-lg">
        <form wire:submit="add" class="space-y-6">
            <flux:heading size="lg">{{ __('Add booking') }}</flux:heading>

            <flux:select wire:model.live="serviceId" label="{{ __('Service') }}">
                <flux:select.option value="">{{ __('Blocked time (no service)') }}</flux:select.option>
                @foreach ($this->services as $service)
                    <flux:select.option value="{{ $service->id }}">
                        {{ $service->title ?: __('Untitled') }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:date-picker wire:model="day" label="{{ __('Day') }}" />
                <flux:input type="time" step="300" wire:model="time" label="{{ __('Time') }}" />
            </div>

            @if ($serviceId === '')
                <flux:input
                    type="number"
                    step="5"
                    min="5"
                    wire:model="duration"
                    label="{{ __('Length in minutes') }}"
                />
            @endif

            <flux:text>{{ __('Times are in :zone. Your weekly hours don’t apply here, but the time must be free.', ['zone' => str_replace('_', ' ', $siteZone)]) }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" label="{{ __('Name') }}" />
                <flux:input
                    type="email"
                    wire:model="email"
                    label="{{ __('Email') }}"
                    description="{{ __('They get a confirmation email.') }}"
                />
            </div>
            <flux:input wire:model="phone" label="{{ __('Phone') }}" />
            <flux:textarea wire:model="notes" rows="3" label="{{ __('Notes') }}" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Add booking') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

@section('header-content')
    <flux:breadcrumbs>
        <flux:breadcrumbs.item class="pl-3 md:pl-0">{{ __('Bookings') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
@endsection
