@props(['site'])

@if ($gaId = $site->googleAnalyticsId())
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', @js($gaId));
    </script>
@endif

@if ($site->headScripts() !== '')
    {!! $site->headScripts() !!}
@endif
