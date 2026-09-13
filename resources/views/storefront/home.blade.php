@extends('layouts.storefront')

@section('title', 'Moscow Trade | Wholesale Groceries')
@section('description', 'Shop featured wholesale groceries, beverages and everyday essentials from Moscow Trade in the Maldives.')

@section('content')
<section class="store-container pt-5 sm:pt-7">
    <div class="store-supermarket-hero relative overflow-hidden rounded-[1.75rem]">
        <div class="relative z-10 grid min-h-[430px] lg:grid-cols-[.9fr_1.1fr]">
            <div class="flex flex-col justify-center px-7 py-12 sm:px-12 lg:px-16 lg:py-16">
                <span class="store-eyebrow">Moscow Trade</span>
                <h1 class="mt-5 max-w-xl text-4xl font-black leading-[1.03] tracking-[-.04em] text-[#173723] sm:text-5xl lg:text-6xl">Everyday groceries.<br><span class="text-[#2d8c3f]">Better wholesale value.</span></h1>
                <p class="mt-5 max-w-lg text-base leading-7 text-slate-600 sm:text-lg">Stock your shop, café or business with groceries, beverages and household essentials from one reliable supplier.</p>
                <div class="mt-8 flex flex-wrap gap-3"><a href="#featured" class="store-hero-button">Shop Featured</a><a href="{{ route('store.contact') }}" class="store-hero-secondary">Contact Us</a></div>
                <div class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-xs font-extrabold text-[#245f32]"><span>✓ Wholesale pricing</span><span>✓ Easy online ordering</span><span>✓ Local support</span></div>
            </div>
            <div class="store-grocery-display relative hidden min-h-[430px] items-end justify-center p-10 lg:flex" aria-hidden="true">
                <div class="store-grocery-bag">
                    <span class="store-grocery-item store-grocery-item-one">RICE</span>
                    <span class="store-grocery-item store-grocery-item-two">OIL</span>
                    <span class="store-grocery-item store-grocery-item-three">MILK</span>
                    <span class="store-grocery-item store-grocery-item-four">SAUCE</span>
                    <div class="store-grocery-bag-front"><strong>MOSCOW</strong><small>TRADERS WHOLESALE</small></div>
                </div>
                <span class="store-grocery-leaf leaf-one"></span><span class="store-grocery-leaf leaf-two"></span>
            </div>
        </div>
    </div>
</section>

<section id="categories" class="store-container mt-5">
    <div class="grid overflow-hidden rounded-2xl border border-[#dce8d5] bg-white shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        @foreach([['Bulk & case pricing','Value for your business'],['Grocery essentials','Everything you need'],['Secure ordering','Simple online checkout'],['Friendly support','We are here to help']] as [$title, $copy])
            <div class="store-benefit"><span class="store-benefit-check">✓</span><div><strong>{{ $title }}</strong><small>{{ $copy }}</small></div></div>
        @endforeach
    </div>
</section>

@if($categories->isNotEmpty())
<section class="store-container mt-10">
    <div class="flex gap-3 overflow-x-auto pb-2">
        @foreach($categories as $category)
            <a href="{{ route('store.category', $category->slug) }}" class="store-category-chip"><span>{{ strtoupper(substr($category->name, 0, 1)) }}</span><strong>{{ $category->name }}</strong><small>{{ $category->products_count }}</small></a>
        @endforeach
    </div>
</section>
@endif

<section id="featured" class="store-section store-container">
    <div class="store-section-heading">
        <div><span class="store-kicker">Our selection</span><h2>Featured Products</h2><p class="mt-2 max-w-xl text-sm leading-6 text-slate-500">Hand-picked wholesale groceries and everyday essentials available to order.</p></div>
        <a href="{{ route('store.shop') }}">View all products →</a>
    </div>
    @if($featured->isNotEmpty())
        <div class="store-product-grid">@foreach($featured as $product)<x-storefront.product-card :product="$product" />@endforeach</div>
    @else
        <div class="store-empty">No featured products are published yet. Mark products as featured in the admin area to show them here.</div>
    @endif
</section>

<section class="store-section store-container">
    <div class="store-bulk-banner overflow-hidden rounded-[1.75rem] px-7 py-11 sm:px-12 lg:flex lg:items-center lg:justify-between lg:px-16">
        <div class="max-w-2xl"><span class="text-xs font-black uppercase tracking-[.2em] text-lime-300">Need a larger order?</span><h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">Wholesale supply made straightforward.</h2><p class="mt-3 max-w-xl leading-7 text-green-50/70">Tell us what your business needs and our team will help with product availability and ordering.</p></div><a href="{{ route('store.contact') }}" class="store-button-light mt-7 lg:mt-0">Talk to Our Team</a>
    </div>
</section>
@endsection
