@props(['product'])
@php
    $catalog = app(\App\Services\StorefrontCatalog::class);
    $price = $catalog->price($product);
    $regularPrice = $catalog->regularPrice($product);
    $stock = $catalog->available($product);
    $image = collect($product->images ?? [])->first();
    $imageUrl = $image ? \Illuminate\Support\Facades\Storage::disk('public')->url($image) : null;
@endphp
<article class="store-product-card group flex h-full flex-col overflow-hidden bg-white">
    <a href="{{ route('store.product', $product->slug) }}" class="relative block aspect-square overflow-hidden bg-[#f6f8f1]">
        @if($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" width="640" height="640" loading="lazy" class="h-full w-full object-contain p-4 transition duration-500 group-hover:scale-105">
        @else
            <span class="store-product-placeholder" aria-hidden="true"><svg viewBox="0 0 120 120" fill="none"><path d="M28 45h64l-6 56H34l-6-56Z" fill="currentColor" opacity=".12"/><path d="M35 45c2-17 10-27 25-27s23 10 25 27M28 45h64l-6 56H34l-6-56Z" stroke="currentColor" stroke-width="5" stroke-linejoin="round"/><path d="M47 62h26M47 75h26" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg></span>
        @endif
        @if($product->sale_price && $price < $regularPrice)<span class="absolute top-3 left-3 rounded-lg bg-[#ed552f] px-3 py-1 text-xs font-black text-white">Special</span>@endif
    </a>
    <div class="flex flex-1 flex-col p-4 sm:p-5">
        <p class="mb-1 text-[10px] font-black uppercase tracking-[.15em] text-[#398c3c]">{{ $product->category?->name ?? 'Groceries' }}</p>
        <h3 class="line-clamp-2 min-h-12 text-sm font-extrabold leading-6 text-slate-900 sm:text-base"><a href="{{ route('store.product', $product->slug) }}">{{ $product->name }}</a></h3>
        <div class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1"><strong class="text-lg text-[#173723]">{{ $product->branchPrices->first()?->currency ?? 'MVR' }} {{ number_format($price, 2) }}</strong>@if($price < $regularPrice)<del class="text-xs text-slate-400">{{ number_format($regularPrice, 2) }}</del>@endif</div>
        <div class="mt-1 flex items-center justify-between gap-2"><span class="text-[10px] font-semibold text-slate-400">{{ $product->is_taxable ? 'GST inclusive' : 'GST exempt' }}</span><span class="text-[10px] font-bold {{ $stock > 0 ? 'text-emerald-700' : 'text-rose-600' }}">{{ $stock > 0 ? 'In stock' : 'Out of stock' }}</span></div>
        <form method="POST" action="{{ route('store.cart.add') }}" class="mt-auto pt-4">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="quantity" value="1"><button class="store-button-primary w-full justify-center px-3 py-2.5 text-sm" @disabled($stock <= 0)><span class="mr-2 text-lg leading-none">+</span> Add to Cart</button></form>
    </div>
</article>
