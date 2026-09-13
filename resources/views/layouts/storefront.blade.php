@php
    $store = app(\App\Services\StorefrontContext::class);
    $storeCompany = $store->company();
    $storeCustomer = app(\App\Services\StorefrontCustomerSession::class)->customer(request(), $storeCompany);
    $cartCount = collect(session('store_cart', []))->sum();
    $logoCandidates = ['images/moscow logo.png', 'images/moscow-traders-wholesale/logo.webp', 'images/moscow-traders-wholesale/logo.png', 'images/moscow-traders-wholesale/logo.svg', 'logo.png', 'logo.svg'];
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
<body class="storefront-body min-h-screen bg-[#fbfcf8] text-slate-950 antialiased">
    <header class="store-header sticky top-0 z-50 bg-white">
        <div class="store-utility-bar">
            <div class="store-container flex items-center justify-between gap-4 py-2 text-[11px] font-bold sm:text-xs">
                <span>Wholesale grocery essentials for shops, cafés and businesses</span>
                <span class="hidden sm:inline">Malé, Maldives @if($storeCompany->phone) · {{ $storeCompany->phone }} @endif</span>
            </div>
        </div>

        <div class="store-container grid min-h-20 grid-cols-[auto_1fr_auto] items-center gap-3 py-3 lg:min-h-24 lg:gap-8">
            <a href="{{ route('store.home') }}" class="flex shrink-0 items-center gap-3" aria-label="Moscow Traders Wholesale home">
                @if($logoPath)
                    <img src="{{ asset($logoPath) }}" width="1024" height="1024" alt="Moscow Traders Wholesale" class="h-14 w-14 object-contain sm:h-16 sm:w-16">
                @else
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-[#66c11f] text-lg font-black text-white">MT</span>
                @endif
                <span class="hidden leading-tight md:block"><strong class="block text-lg font-black tracking-tight">Moscow Traders</strong><small class="text-[10px] font-black uppercase tracking-[.2em] text-[#26833c]">Wholesale</small></span>
            </a>

            <form action="{{ route('store.shop') }}" class="hidden md:block">
                <label class="relative block">
                    <span class="sr-only">Search grocery products</span>
                    <input name="q" value="{{ request('q') }}" placeholder="Search rice, drinks, sauces, dairy and more..." class="store-search-input">
                    <svg class="absolute top-1/2 left-4 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </label>
            </form>

            <div class="ml-auto flex items-center gap-1 sm:gap-2">
                <a href="{{ route('store.shop') }}#categories" class="store-header-action hidden lg:flex">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><span>Categories</span>
                </a>
                <a href="{{ route('store.cart') }}" class="store-header-action relative flex">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 4h2l2.3 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21 7H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg><span class="hidden sm:inline">Cart</span>
                    @if($cartCount)<b class="absolute top-0 right-0 grid min-h-5 min-w-5 place-items-center rounded-full bg-[#ed552f] px-1 text-[10px] text-white">{{ min(99, $cartCount) }}</b>@endif
                </a>
                @if($storeCustomer)
                    <details class="relative hidden sm:block">
                        <summary class="store-header-action flex cursor-pointer list-none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c.7-5 3.4-7 8-7s7.3 2 8 7"/></svg><span>{{ \Illuminate\Support\Str::before($storeCustomer->name, ' ') }}</span></summary>
                        <div class="absolute right-0 mt-3 w-56 rounded-2xl border border-slate-200 bg-white p-2 text-sm shadow-2xl"><div class="border-b border-slate-100 px-3 py-2"><strong class="block truncate">{{ $storeCustomer->name }}</strong><small class="text-slate-500">{{ $storeCustomer->phone }}</small></div><a class="store-mobile-link mt-1 block" href="{{ route('store.register') }}">Account profile</a><form method="POST" action="{{ route('store.customer.logout') }}">@csrf<button class="store-mobile-link w-full text-left text-rose-600">Sign out</button></form></div>
                    </details>
                @else
                    <a href="{{ route('store.register') }}" class="store-header-action hidden sm:flex"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c.7-5 3.4-7 8-7s7.3 2 8 7"/></svg><span>Account</span></a>
                @endif
                <details class="relative lg:hidden">
                    <summary class="grid h-11 w-11 cursor-pointer list-none place-items-center rounded-xl hover:bg-green-50" aria-label="Open menu"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg></summary>
                    <div class="absolute right-0 mt-3 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl"><nav class="grid gap-1 text-sm font-semibold"><a class="store-mobile-link" href="{{ route('store.home') }}">Home</a><a class="store-mobile-link" href="{{ route('store.shop') }}">All Products</a><a class="store-mobile-link" href="{{ route('store.shop') }}#categories">Categories</a><a class="store-mobile-link" href="{{ route('store.contact') }}">Contact</a>@if(!$storeCustomer)<a class="store-mobile-link" href="{{ route('store.register') }}">Account</a>@endif</nav></div>
                </details>
            </div>
        </div>

        <div class="store-container pb-3 md:hidden"><form action="{{ route('store.shop') }}"><label class="relative block"><span class="sr-only">Search grocery products</span><input name="q" value="{{ request('q') }}" placeholder="Search products..." class="store-search-input"><svg class="absolute top-1/2 left-4 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></label></form></div>

        <nav class="store-category-nav" aria-label="Product categories">
            <div class="store-container flex items-center gap-7 overflow-x-auto py-3 text-xs font-black uppercase tracking-[.1em] whitespace-nowrap">
                <a href="{{ route('store.shop') }}" class="store-all-categories"><span>☰</span> All Products</a>
                @foreach($storeCategories as $navCategory)<a href="{{ route('store.category', $navCategory->slug) }}">{{ $navCategory->name }}</a>@endforeach
                <a href="{{ route('store.contact') }}" class="ml-auto">Contact</a>
            </div>
        </nav>
    </header>

    @if(session('success'))<div class="store-container pt-4"><div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800">{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="store-container pt-4"><div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ $errors->first() }}</div></div>@endif

    <main>@yield('content')</main>

    <footer class="mt-20 bg-[#12391f] text-green-50/80">
        <div class="store-container grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-4">
            <div><div class="mb-4 flex items-center gap-3">@if($logoPath)<img src="{{ asset($logoPath) }}" width="1024" height="1024" alt="Moscow Traders Wholesale" class="h-16 w-16 rounded-full bg-white object-contain">@endif<div><strong class="block text-lg text-white">Moscow Traders</strong><small class="font-bold uppercase tracking-[.18em] text-lime-300">Wholesale</small></div></div><p class="max-w-xs text-sm leading-6 text-green-50/60">Wholesale groceries, beverages and everyday essentials for your business.</p></div>
            <div><h2 class="store-footer-title">Shop</h2><div class="grid gap-2.5 text-sm"><a href="{{ route('store.shop') }}">All Products</a>@foreach($storeCategories->take(5) as $footerCategory)<a href="{{ route('store.category', $footerCategory->slug) }}">{{ $footerCategory->name }}</a>@endforeach</div></div>
            <div><h2 class="store-footer-title">Help</h2><div class="grid gap-2.5 text-sm"><a href="{{ route('store.contact') }}">Contact Us</a><a href="{{ route('store.contact') }}#location">Maldives</a><a href="{{ route('store.shop') }}">Browse Categories</a></div></div>
            <div><h2 class="store-footer-title">Contact</h2><div class="grid gap-2.5 text-sm text-green-50/60">@if($storeCompany->phone)<a href="tel:{{ $storeCompany->phone }}">{{ $storeCompany->phone }}</a>@endif @if($storeCompany->email)<a href="mailto:{{ $storeCompany->email }}">{{ $storeCompany->email }}</a>@endif <span>Malé, Maldives</span></div></div>
        </div>
        <div class="store-container flex flex-col gap-2 border-t border-white/10 py-6 text-xs text-green-50/50 sm:flex-row sm:items-center sm:justify-between"><span>© {{ now()->year }} Moscow Traders Wholesale. All rights reserved.</span><span>Website created by <a href="https://micronet.mv" target="_blank" rel="noopener noreferrer" class="font-semibold text-white transition hover:text-lime-300">micronet.mv</a></span></div>
    </footer>

    @if($storeCompany->website_whatsapp)<a href="https://wa.me/{{ preg_replace('/\D+/', '', $storeCompany->website_whatsapp) }}" target="_blank" rel="noopener" class="fixed right-5 bottom-5 z-40 grid h-14 w-14 place-items-center rounded-full bg-[#25a244] font-black text-white shadow-xl" aria-label="Contact Moscow Traders Wholesale on WhatsApp">WA</a>@endif
    @stack('scripts')
</body>
</html>
