<?php

declare(strict_types=1);

use App\Actions\UpdateSettingsAction;
use App\Services\BlockHtmlSanitizer;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

return new class extends Component
{
    private const array LABELS = ['accept', 'decline', 'link'];

    public bool $enabled = false;

    /**
     * @var array<string, string>
     */
    public array $message = [];

    /**
     * @var array<string, string>
     */
    public array $accept = [];

    /**
     * @var array<string, string>
     */
    public array $decline = [];

    /**
     * @var array<string, string>
     */
    public array $link = [];

    #[Url(except: 'en')]
    public string $locale;

    /**
     * @var array<string, mixed>
     */
    public array $activeLocales = [];

    public function mount(): void
    {
        $this->enabled = (bool) config('site.cookie_consent_enabled', false);
        $this->message = $this->localeMap('message');
        $this->accept = $this->localeMap('accept');
        $this->decline = $this->localeMap('decline');
        $this->link = $this->localeMap('link');

        $this->activeLocales = resolve('localization')->getActiveLocales();

        foreach (array_keys($this->activeLocales) as $code) {
            $this->message[$code] ??= '<p>'.e(__('We use cookies to understand how visitors use this site.', [], $code)).'</p>';
            $this->accept[$code] ??= __('Accept', [], $code);
            $this->decline[$code] ??= __('Decline', [], $code);
            $this->link[$code] ??= __('Cookie settings', [], $code);
        }

        if (! isset($this->locale) || ! array_key_exists($this->locale, $this->activeLocales)) {
            $this->locale = app()->getLocale();
        }
    }

    public function update(UpdateSettingsAction $action): void
    {
        $this->authorize('settings.edit');

        /** @var array<string, array<int, mixed>> */
        $rules = ['enabled' => ['boolean']];

        foreach (array_keys($this->activeLocales) as $locale) {
            $rules["message.$locale"] = ['nullable', 'string', 'max:1000'];

            foreach (self::LABELS as $label) {
                $rules["$label.$locale"] = ['nullable', 'string', 'max:40'];
            }
        }

        $messages = [
            'message.*.max' => __('The message may not exceed :max characters.'),
            'accept.*.max' => __('The Accept button may not exceed :max characters.'),
            'decline.*.max' => __('The Decline button may not exceed :max characters.'),
            'link.*.max' => __('The footer link may not exceed :max characters.'),
        ];

        try {
            $validated = $this->validate($rules, $messages);
        } catch (ValidationException $e) {
            $this->revealErrors($e);

            throw $e;
        }

        $action->handle([
            'cookie_consent_enabled' => $validated['enabled'],
            'cookie_consent_message' => $this->cleanMessages($validated['message'] ?? []),
            'cookie_consent_accept' => $this->filledOnly($validated['accept'] ?? []),
            'cookie_consent_decline' => $this->filledOnly($validated['decline'] ?? []),
            'cookie_consent_link' => $this->filledOnly($validated['link'] ?? []),
        ]);

        Flux::toast(__('Cookie consent has been updated.'), variant: 'success');
    }

    #[On('change-locale')]
    public function changeLocale(): void
    {
        $codes = array_keys($this->activeLocales);
        $index = array_search($this->locale, $codes, true);

        $this->locale = $codes[($index + 1) % count($codes)] ?? $this->locale;
    }

    public function render(): View
    {
        return $this->view()
            ->title(__('Cookie consent'))
            ->layout('layouts::admin');
    }

    private function revealErrors(ValidationException $e): void
    {
        $codes = array_keys($this->activeLocales);

        foreach (array_keys($e->errors()) as $key) {
            $locale = str($key)->afterLast('.')->value();

            if (in_array($locale, $codes, true)) {
                $this->locale = $locale;

                return;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private function filledOnly(array $values): array
    {
        $filled = [];

        foreach ($values as $code => $text) {
            if (is_string($code) && is_string($text) && mb_trim(strip_tags($text)) !== '') {
                $filled[$code] = mb_trim($text);
            }
        }

        return $filled;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private function cleanMessages(array $values): array
    {
        return $this->filledOnly(array_map(BlockHtmlSanitizer::value(...), $this->filledOnly($values)));
    }

    /**
     * @return array<string, string>
     */
    private function localeMap(string $field): array
    {
        $value = config('site.cookie_consent_'.$field);

        return is_array($value) ? $this->filledOnly($value) : [];
    }
};
?>

<x-admin.settings-layout>
    <form
        wire:submit="update"
        wire:warn-dirty="{{ __('Leaving? Changes you made may not be saved.') }}"
        class="grid items-start gap-10 md:grid-cols-5"
    >
        <div class="space-y-8 md:col-span-3">
            <flux:switch
                wire:model="enabled"
                align="left"
                :label="__('Ask visitors before loading analytics')"
                :description="__('Google Analytics and custom code wait until a visitor accepts.')"
            />

            <x-forms.texteditor-translated
                name="message"
                :$locale
                :multi-locale="count($activeLocales) > 1"
                :label="__('Message')"
                :placeholder="__('We use cookies to understand how visitors use this site.')"
                toolbar="bold italic | link"
            />

            <div class="grid gap-6 sm:grid-cols-3">
                <x-forms.input-translated
                    name="accept"
                    :$locale
                    :multi-locale="count($activeLocales) > 1"
                    :label="__('Accept button')"
                    :placeholder="__('Accept')"
                />
                <x-forms.input-translated
                    name="decline"
                    :$locale
                    :multi-locale="count($activeLocales) > 1"
                    :label="__('Decline button')"
                    :placeholder="__('Decline')"
                />
                <x-forms.input-translated
                    name="link"
                    :$locale
                    :multi-locale="count($activeLocales) > 1"
                    :label="__('Footer link')"
                    :placeholder="__('Cookie settings')"
                />
            </div>

            <div>
                <flux:button type="submit" variant="primary" icon="check"> {{ __('Update') }} </flux:button>
            </div>
        </div>

        <div class="md:sticky md:top-20 md:col-span-2">
            <flux:text class="mb-3">{{ __('Preview') }}</flux:text>
            <div class="space-y-4 rounded-lg border border-zinc-200 p-5 dark:border-zinc-700" data-consent-preview>
                <div
                    class="[&_a]:underline text-sm text-zinc-700 dark:text-zinc-200"
                    x-html="$wire.message[$wire.locale] || @js('<p>'.e(__('We use cookies to understand how visitors use this site.')).'</p>')"
                ></div>
                <div class="flex gap-2 text-sm font-medium">
                    <span
                        class="rounded-md bg-zinc-100 px-3 py-1.5 text-zinc-800 dark:bg-white/10 dark:text-white"
                        x-text="$wire.decline[$wire.locale] || @js(__('Decline'))"
                    ></span>
                    <span
                        class="rounded-md bg-zinc-800 px-3 py-1.5 text-white dark:bg-white dark:text-zinc-800"
                        x-text="$wire.accept[$wire.locale] || @js(__('Accept'))"
                    ></span>
                </div>
            </div>
        </div>
    </form>
</x-admin.settings-layout>

@section('header-content')
    <flux:breadcrumbs class="hidden md:flex">
        <flux:breadcrumbs.item href="{{ route('admin.settings-general') }}" wire:navigate>
            {{ __('Settings') }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item> {{ __('Cookie consent') }} </flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <flux:dropdown class="md:hidden">
        <flux:navbar.item icon-trailing="chevron-down">{{ __('Cookie consent') }}</flux:navbar.item>

        <flux:navmenu>
            <flux:navmenu.item href="{{ route('admin.settings-general') }}" wire:navigate>
                {{ __('Settings') }}</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>
@endsection
