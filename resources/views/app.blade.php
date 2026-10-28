<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme ?? 'paper' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=2">
        <link rel="manifest" href="/pwa/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/pwa/icon-180.png">
        <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F0EEE9">
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#151412">
        <meta name="apple-mobile-web-app-title" content="CRM Solo">
        @if (\App\Support\SwitzerFont::selfHosted())
            <link rel="preload" href="{{ asset(\App\Support\SwitzerFont::FILE) }}" as="font" type="font/woff2" crossorigin>
            <style>
                @font-face {
                    font-family: 'Switzer';
                    font-style: normal;
                    font-weight: 100 900;
                    font-display: swap;
                    src: url('{{ asset(\App\Support\SwitzerFont::FILE) }}') format('woff2');
                }
            </style>
        @else
            <link rel="preconnect" href="https://api.fontshare.com" crossorigin>
            <link rel="preconnect" href="https://cdn.fontshare.com" crossorigin>
            <link rel="stylesheet" href="https://api.fontshare.com/v2/css?f[]=switzer@400,500,600,700&display=swap">
        @endif

        @viteReactRefresh
        @vite(['resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
