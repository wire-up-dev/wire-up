@props(['changed' => false, 'reloadUrl'])

@if ($changed)
    <flux:callout icon="arrow-path" variant="warning" class="mb-6" data-content-changed>
        <flux:callout.heading>{{ __('This was changed after you opened it') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('The AI assistant or another person saved changes here. Reload to see them. Anything you haven’t saved will be lost.') }}
        </flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" :href="$reloadUrl">{{ __('Reload') }}</flux:button>
        </x-slot>
    </flux:callout>
@endif

<flux:modal name="content-changed" class="md:max-w-md">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('This was changed after you opened it') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Your save was stopped so the changes saved since aren’t lost. Reload to see them. Anything you haven’t saved will be lost.') }}
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Keep editing') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" :href="$reloadUrl">{{ __('Reload') }}</flux:button>
        </div>
    </div>
</flux:modal>
