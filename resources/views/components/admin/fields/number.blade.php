@php
    $key = $field['key'];
    $translatable = (bool) ($field['translatable'] ?? false);
    $required = (bool) ($field['required'] ?? false);
    $help = (string) ($field['help'] ?? '');
    $label = $field['label'][$locale] ?? \Illuminate\Support\Arr::first($field['label']) ?? $key;
    $path = $translatable ? "data.{$key}.{$locale}" : "data.{$key}";
    $inMinutes = $key === \App\Services\RecordTypePresets::DURATION_FIELD && (bool) ($recordType->bookable ?? false);
@endphp

<flux:field wire:key="field-{{ $key }}-{{ $translatable ? $locale : 'shared' }}">
    @include('components.admin.fields.partials.label')
    @if ($inMinutes)
        <flux:input.group>
            <flux:input type="number" step="5" min="5" wire:model.lazy="{{ $path }}" :required="$required" />
            <flux:input.group.suffix>{{ __('min') }}</flux:input.group.suffix>
        </flux:input.group>
    @else
        <flux:input type="number" step="any" wire:model.lazy="{{ $path }}" :required="$required" />
    @endif
    @if ($help)
        <flux:description>{{ $help }}</flux:description>
    @endif
    <flux:error name="{{ $path }}" />
</flux:field>
