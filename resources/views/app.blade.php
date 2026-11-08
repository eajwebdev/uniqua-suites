<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @isset($seo)
    {{-- Read by search engines and link previews without running JavaScript. The page replaces the inertia-marked tags with its own when it loads. --}}
    <title inertia>{{ $seo['title'] }}</title>
    <meta inertia name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['url'] }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $seo['site'] }}">
    <meta inertia property="og:title" content="{{ $seo['title'] }}">
    <meta inertia property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['url'] }}">
    <meta property="og:locale" content="en_PH">
    @if ($seo['image'])
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta name="twitter:card" content="summary_large_image">
    @endif
    @foreach ($seo['schema'] as $schema)
    <script type="application/ld+json">{!! \App\Support\Seo::json($schema) !!}</script>
    @endforeach
    @else
    <meta name="robots" content="noindex, nofollow">
    @endisset
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
