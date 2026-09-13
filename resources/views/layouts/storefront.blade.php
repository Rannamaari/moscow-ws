@php
    $store = app(\App\Services\StorefrontContext::class);
    $storeCompany = $store->company();
    $storeCustomer = app(\App\Services\StorefrontCustomerSession::class)->customer(request(), $storeCompany);
    $cartCount = collect(session('store_cart', []))->sum();
    $logoCandidates = ['images/moscow-traders-wholesale/logo.webp', 'images/moscow-traders-wholesale/logo.png', 'images/moscow-traders-wholesale/logo.svg', 'logo.png', 'logo.svg'];
    $logoPath = collect($logoCandidates)->first(fn ($path) => file_exists(public_path($path)));
    $pageTitle = trim($__env->yieldContent('title', 'Moscow Traders Wholesale'));
    $pageDescription = trim($__env->yieldContent('description', 'Shop wholesale groceries and everyday essentials from Moscow Traders Wholesale in the Maldives.'));
    $canonical = trim($__env->yieldContent('canonical', url()->current()));
    $socialImage = trim($__env->yieldContent('social_image', $logoPath ? asset($logoPath) : ''));
    $robots = trim($__env->yieldContent('robots', 'index, follow, max-image-preview:large'));
    $storeCategories = \App\Models\Category::query()->where('company_id', $storeCompany->id)->where('is_active', true)->whereHas('products', fn ($query) => $query->visibleOnline())->orderBy('name')->limit(8)->get(['name', 'slug']);
    $localBusinessSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        '@id' => route('store.home').'#store',
        'name' => 'Moscow Traders Wholesale',
        'url' => route('store.home'),
        'logo' => $logoPath ? asset($logoPath) : null,
        'image' => $socialImage ?: null,
        'telephone' => $storeCompany->phone,
        'email' => $storeCompany->email,
        'currenciesAccepted' => 'MVR',
        'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'MV'],
        'areaServed' => [['@type' => 'Country', 'name' => 'Maldives']],
    ]);
    $configuredAnalyticsId = (string) config('services.google.analytics_measurement_id');
    $googleAnalyticsId = preg_match('/^G-[A-Z0-9]+$/i', $configuredAnalyticsId) ? strtoupper($configuredAnalyticsId) : null;
    $googleSiteVerification = trim((string) config('services.google.site_verification'));
@endphp
<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="{{ $robots }}">
    @if($googleSiteVerification)<meta name="google-site-verification" content="{{ $googleSiteVerification }}">@endif
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:site_name" content="Moscow Traders Wholesale">
    <meta property="og:locale" content="en_MV">
    @if($socialImage)<meta property="og:image" content="{{ $socialImage }}">@endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    @if($socialImage)<meta name="twitter:image" content="{{ $socialImage }}">@endif
    <title>{{ $pageTitle }}</title>
    <script type="application/ld+json">{!! json_encode($localBusinessSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @if($googleAnalyticsId)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $googleAnalyticsId }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @json($googleAnalyticsId));
        </script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="storefront-body min-h-screen bg-slate-50 text-slate-950 antialiased">
    <header class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
        <div class="store-container flex h-16 items-center gap-3 lg:h-20">
            <a href="{{ route('store.home') }}" class="flex shrink-0 items-center gap-2.5" aria-label="Moscow Traders Wholesale home">
                @if($logoPath)
                    <img src="{{ asset($logoPath) }}" width="1774" height="887" alt="Moscow Traders Wholesale" class="h-11 w-[88px] object-contain lg:h-14 lg:w-28">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-2xl bg-indigo-600 text-lg font-black text-white shadow-lg shadow-indigo-200">MT</span>
                    <span class="leading-none"><strong class="block text-base font-black tracking-tight lg:text-lg">Moscow Traders Wholesale</strong><small class="hidden text-[10px] font-bold uppercase tracking-[.18em] text-indigo-600 sm:block">Wholesale Groceries</small></span>
                @endif
            </a>

            <nav class="ml-auto hidden items-center gap-7 text-sm font-semibold text-slate-600 lg:flex" aria-label="Main navigation">
                <a class="store-nav-link" href="{{ route('store.home') }}">Home</a>
                <a class="store-nav-link" href="{{ route('store.shop') }}">Shop</a>
                <a class="store-nav-link" href="{{ route('store.shop') }}#categories">Categories</a>
                <a class="store-nav-link" href="{{ route('store.shop', ['sort' => 'newest']) }}">New Arrivals</a>
                <a class="store-nav-link" href="{{ route('store.contact') }}">Contact</a>
            </nav>

            <div class="ml-auto flex items-center gap-1.5 lg:ml-7">
                <form action="{{ route('store.shop') }}" class="hidden xl:block">
                    <label class="relative block">
                        <span class="sr-only">Search products</span>
                        <input name="q" value="{{ request('q') }}" placeholder="Search products" class="w-52 rounded-full border border-slate-200 bg-slate-50 py-2.5 pr-4 pl-10 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        <svg class="absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    </label>
                </form>
                <a href="{{ route('store.cart') }}" class="relative grid h-11 w-11 place-items-center rounded-full text-slate-700 transition hover:bg-slate-100" aria-label="Cart with {{ $cartCount }} items">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
                    @if($cartCount)<span class="absolute top-0 right-0 grid min-h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-black text-white">{{ min(99, $cartCount) }}</span>@endif
                </a>
                @if($storeCustomer)
                    <details class="relative hidden sm:block">
                        <summary class="store-button-primary cursor-pointer list-none px-5 py-2.5">{{ \Illuminate\Support\Str::before($storeCustomer->name, ' ') }}</summary>
                        <div class="absolute right-0 mt-3 w-56 rounded-2xl border border-slate-200 bg-white p-2 text-sm shadow-2xl">
                            <div class="border-b border-slate-100 px-3 py-2"><strong class="block truncate">{{ $storeCustomer->name }}</strong><small class="text-slate-500">{{ $storeCustomer->phone }}</small></div>
                            <a class="store-mobile-link mt-1 block" href="{{ route('store.register') }}">Account profile</a>
                            <form method="POST" action="{{ route('store.customer.logout') }}">@csrf<button class="store-mobile-link w-full text-left text-rose-600">Sign out</button></form>
                        </div>
                    </details>
                @else
                    <a href="{{ route('store.register') }}" class="store-button-primary hidden px-5 py-2.5 sm:inline-flex">Register</a>
                @endif
                <details class="relative lg:hidden">
                    <summary class="grid h-11 w-11 cursor-pointer list-none place-items-center rounded-full hover:bg-slate-100" aria-label="Open menu">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </summary>
                    <div class="absolute right-0 mt-3 w-72 rounded-3xl border border-slate-200 bg-white p-4 shadow-2xl">
                        <form action="{{ route('store.shop') }}" class="mb-3"><input name="q" placeholder="Search products" class="store-input"></form>
                        <nav class="grid gap-1 text-sm font-semibold"><a class="store-mobile-link" href="{{ route('store.home') }}">Home</a><a class="store-mobile-link" href="{{ route('store.shop') }}">Shop</a><a class="store-mobile-link" href="{{ route('store.shop', ['sort' => 'newest']) }}">New Arrivals</a><a class="store-mobile-link" href="{{ route('store.contact') }}">Contact</a>@if($storeCustomer)<a class="store-mobile-link" href="{{ route('store.register') }}">Account profile</a><form method="POST" action="{{ route('store.customer.logout') }}">@csrf<button class="store-mobile-link w-full text-left text-rose-600">Sign out</button></form>@else<a class="store-mobile-link" href="{{ route('store.register') }}">Register</a>@endif</nav>
                    </div>
                </details>
            </div>
        </div>
    </header>

    @if(session('success'))<div class="store-container pt-4"><div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800">{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="store-container pt-4"><div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ $errors->first() }}</div></div>@endif

    <main>@yield('content')</main>

    <footer class="mt-20 bg-slate-950 text-slate-300">
        <div class="store-container grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-4">
            <div><div class="mb-4">@if($logoPath)<img src="{{ asset($logoPath) }}" width="1774" height="887" alt="Moscow Traders Wholesale" class="h-16 w-32 object-contain">@else<div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-2xl bg-indigo-500 font-black text-white">MT</span><strong class="text-lg text-white">Moscow Traders Wholesale</strong></div>@endif</div><p class="max-w-xs text-sm leading-6 text-slate-400">Wholesale groceries, beverages and everyday essentials for your business.</p></div>
            <div><h2 class="store-footer-title">Shop</h2><div class="grid gap-2.5 text-sm"><a href="{{ route('store.shop') }}">All Products</a>@foreach($storeCategories->take(5) as $footerCategory)<a href="{{ route('store.category', $footerCategory->slug) }}">{{ $footerCategory->name }}</a>@endforeach</div></div>
            <div><h2 class="store-footer-title">Help</h2><div class="grid gap-2.5 text-sm"><a href="{{ route('store.contact') }}">Contact Us</a><a href="{{ route('store.contact') }}#location">Maldives</a><a href="{{ route('store.shop') }}">Browse Categories</a></div></div>
            <div><h2 class="store-footer-title">Contact</h2><div class="grid gap-2.5 text-sm text-slate-400">@if($storeCompany->phone)<a href="tel:{{ $storeCompany->phone }}">{{ $storeCompany->phone }}</a>@endif @if($storeCompany->email)<a href="mailto:{{ $storeCompany->email }}">{{ $storeCompany->email }}</a>@endif <span>Maldives</span></div></div>
        </div>
        <div class="store-container flex flex-col gap-2 border-t border-white/10 py-6 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <span>© {{ now()->year }} Moscow Traders Wholesale. All rights reserved.</span>
            <span>Website created by <a href="https://micronet.mv" target="_blank" rel="noopener noreferrer" class="font-semibold text-slate-300 transition hover:text-white">micronet.mv</a></span>
        </div>
    </footer>

    @if($storeCompany->website_whatsapp)
        <a href="https://wa.me/{{ preg_replace('/\D+/', '', $storeCompany->website_whatsapp) }}" target="_blank" rel="noopener" class="fixed right-5 bottom-5 z-40 grid h-14 w-14 place-items-center rounded-full bg-emerald-500 text-white shadow-xl" aria-label="Contact Moscow Traders Wholesale on WhatsApp">WA</a>
    @endif
    @stack('scripts')
</body>
</html>
