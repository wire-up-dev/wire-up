@php
    $site = \App\Services\SettingsService::current();
    $message = $site->cookieConsentText('message');
    $buttonClass = 'wire-btn inline-flex cursor-pointer flex-1 items-center justify-center rounded-(--wire-btn-radius) px-4 py-2 text-sm font-medium transition hover:opacity-90 sm:flex-none';
@endphp

<div
    x-data="{ open: false }"
    x-init="open = window.wireupConsent?.choice() === null"
    x-on:wireup-consent-open.window="open = true"
    x-show="open"
    x-cloak
    x-transition.opacity
    role="region"
    aria-label="{{ __('Cookie consent') }}"
    data-cookie-consent
    class="fixed inset-x-0 bottom-0 z-50 p-4 sm:right-auto sm:max-w-md"
>
    <div class="wire-card space-y-4 rounded-(--wire-radius) bg-(--wire-card-bg) p-5 text-(--wire-card-text) shadow-xl">
        <div class="[&_a]:text-(--wire-accent) [&_a]:underline [&>p]:m-0 text-sm leading-(--wire-body-leading)">
            @if ($message !== '')
                {!! $message !!}
            @else
                <p>{{ __('We use cookies to understand how visitors use this site.') }}</p>
            @endif
        </div>
        <div class="flex gap-3">
            <button
                type="button"
                x-on:click="
                    window.wireupConsent.decline();
                    open = false;
                "
                class="{{ $buttonClass }} bg-(--wire-secondary-bg) text-(--wire-secondary-text) [--wire-btn-border:var(--wire-secondary-border)]"
            >
                {{ $site->cookieConsentText('decline') ?: __('Decline') }}
            </button>
            <button
                type="button"
                x-on:click="
                    window.wireupConsent.accept();
                    open = false;
                "
                class="{{ $buttonClass }} bg-(--wire-primary-bg) text-(--wire-primary-text) [--wire-btn-border:var(--wire-primary-border)]"
            >
                {{ $site->cookieConsentText('accept') ?: __('Accept') }}
            </button>
        </div>
    </div>
</div>
