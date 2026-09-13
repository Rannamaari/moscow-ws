@extends('layouts.storefront')
@section('title', ($currentCategory?->name ? $currentCategory->name.' | ' : '').'Wholesale Groceries | Moscow Trade')
@section('description', $currentCategory?->description ?: 'Browse wholesale groceries and everyday essentials from Moscow Trade.')
@php
    $seoQuery = collect(request()->query())->except('page')->filter(fn ($value) => filled($value));
    $canonicalBase = $currentCategory ? route('store.category', $currentCategory->slug) : route('store.shop');
    $canonicalUrl = request()->integer('page', 1) > 1 ? $canonicalBase.'?page='.request()->integer('page') : $canonicalBase;
@endphp
@section('canonical', $canonicalUrl)
@if($seoQuery->isNotEmpty()) @section('robots', 'noindex, follow') @endif
@push('head')
@if($currentCategory)
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$currentCategory->name,'description'=>$currentCategory->description ?: "Shop {$currentCategory->name} from Moscow Trade.",'url'=>route('store.category',$currentCategory->slug),'mainEntity'=>['@type'=>'ItemList','numberOfItems'=>$products->total(),'itemListElement'=>$products->getCollection()->values()->map(fn($product,$index)=>['@type'=>'ListItem','position'=>(($products->currentPage()-1)*$products->perPage())+$index+1,'url'=>route('store.product',$product->slug),'name'=>$product->name])->all()]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) !!}</script>
@endif
@endpush

@section('content')
<section class="store-container pt-10 sm:pt-14">
    <span class="store-kicker">Moscow Trade catalogue</span><h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">{{ $currentCategory?->name ?? 'Shop wholesale groceries' }}</h1><p class="mt-3 max-w-2xl text-slate-600">{{ $currentCategory?->description ?: 'Browse beverages, food staples, sauces and everyday essentials at wholesale prices.' }}</p>
</section>
<section id="categories" class="store-container mt-8 flex gap-2 overflow-x-auto pb-2"><a href="{{ route('store.shop') }}" class="store-filter-pill {{ !request('category') ? 'active' : '' }}">All</a>@foreach($categories as $category)<a href="{{ route('store.category', $category->slug) }}" class="store-filter-pill {{ request('category') === $category->id ? 'active' : '' }}">{{ $category->name }}</a>@endforeach</section>
<section class="store-container mt-6 grid items-start gap-8 lg:grid-cols-[260px_1fr]">
    <details class="rounded-3xl border border-slate-200 bg-white p-5 lg:hidden"><summary class="cursor-pointer font-extrabold">Filters & sorting</summary><div class="mt-5">@include('storefront.partials.filters')</div></details>
    <aside class="sticky top-28 hidden rounded-3xl border border-slate-200 bg-white p-6 lg:block"><h2 class="mb-5 text-lg font-black">Filter products</h2>@include('storefront.partials.filters')</aside>
    <div>
        <div class="mb-5 flex items-center justify-between gap-3"><p class="text-sm text-slate-500">{{ $products->total() }} {{ Str::plural('product', $products->total()) }}</p></div>
        @if($products->isNotEmpty())<div class="store-product-grid">@foreach($products as $product)<x-storefront.product-card :product="$product" />@endforeach</div><div class="mt-10">{{ $products->links() }}</div>@else<div class="store-empty py-16"><strong class="block text-lg text-slate-800">No products found</strong><span class="mt-2 block">Try adjusting your search or filters.</span></div>@endif
    </div>
</section>
@endsection
