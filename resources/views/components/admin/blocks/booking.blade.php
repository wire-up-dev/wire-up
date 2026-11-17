@props(['block', 'locale', 'multiLocale' => false, 'index', 'pageOptions' => []])

@php
    $c = "blocks.{$index}.content";
    $services = \App\Models\Record::query()
        ->with('translations')
        ->whereHas('recordType', fn ($query) => $query->where('bookable', true))
        ->orderBy('id')
        ->get();
@endphp

<div class="flex flex-col gap-6">
    @if ($services->isEmpty())
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>
                {{ __('Turn on Bookable for a content type in Settings → Content Types, then add your services there.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <x-forms.texteditor-translated
        name="{{ $c }}.heading"
        :locale="$locale"
        :multi-locale="$multiLocale"
        label="{{ __('Heading') }}"
    />
    <x-admin.blocks.partials.heading-level :name="$c" />
    <x-forms.texteditor-translated
        name="{{ $c }}.intro"
        :locale="$locale"
        :multi-locale="$multiLocale"
        label="{{ __('Intro') }}"
    />

    @if ($services->isNotEmpty())
        <flux:checkbox.group
            wire:model.live="{{ $c }}.recordIds"
            label="{{ __('Services') }}"
            description="{{ __('Leave all unticked to offer every service.') }}"
        >
            @foreach ($services as $service)
                <flux:checkbox :value="$service->id" :label="$service->title ?: __('Untitled')" />
            @endforeach
        </flux:checkbox.group>
    @endif

    <flux:radio.group wire:model.live="{{ $c }}.align" variant="segmented" label="{{ __('Heading alignment') }}">
        <flux:radio value="left" icon="bars-3-bottom-left" label="{{ __('Left') }}" />
        <flux:radio value="center" icon="bars-3" label="{{ __('Center') }}" />
    </flux:radio.group>

    <flux:switch wire:model.live="{{ $c }}.showPrices" label="{{ __('Show prices') }}" align="left" />

    <flux:radio.group wire:model.live="{{ $c }}.phone" variant="segmented" label="{{ __('Phone number') }}">
        <flux:radio value="off" label="{{ __('Don’t ask') }}" />
        <flux:radio value="optional" label="{{ __('Optional') }}" />
        <flux:radio value="required" label="{{ __('Required') }}" />
    </flux:radio.group>

    <x-forms.input-translated
        name="{{ $c }}.submitText"
        :locale="$locale"
        :multi-locale="$multiLocale"
        label="{{ __('Button text') }}"
        :placeholder="__('Book now')"
    />
    <x-forms.texteditor-translated
        name="{{ $c }}.successMessage"
        :locale="$locale"
        :multi-locale="$multiLocale"
        label="{{ __('Message after booking') }}"
        :placeholder="__('You are booked in. We have sent the details to your email.')"
    />

    <flux:switch wire:model.live="{{ $c }}.hasBackground" label="{{ __('Use background color') }}" align="left" />
</div>
