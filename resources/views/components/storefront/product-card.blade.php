@props(['product'])
@php
    $catalog = app(\App\Services\StorefrontCatalog::class);
    $price = $catalog->price($product);
    $regularPrice = $catalog->regularPrice($product);
    $stock = $catalog->available($product);
    $image = collect($product->images ?? [])->first();
    $imageUrl = $image ? \Illuminate\Support\Facades\Storage::disk('public')->url($image) : null;
@endphp
<article class="group flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200/70">
    <a href="{{ route('store.product', $product->slug) }}" class="relative block aspect-square overflow-hidden bg-gradient-to-br from-slate-50 to-indigo-50">
        @if($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $product->name }}" width="640" height="640" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
        @else
            <span class="store-product-placeholder" aria-hidden="true"><svg viewBox="0 0 120 120" fill="none"><rect x="34" y="18" width="52" height="84" rx="12" stroke="currentColor" stroke-width="5"/><path d="M48 30h24M53 89h14" stroke="currentColor" stroke-width="5" stroke-linecap="round"/><circle cx="60" cy="59" r="16" fill="currentColor" opacity=".12"/></svg></span>
        @endif
        @if($product->sale_price && $price < $regularPrice)<span class="absolute top-3 left-3 rounded-full bg-rose-500 px-3 py-1 text-xs font-black text-white">Sale</span>@endif
    </a>
    <div class="flex flex-1 flex-col p-4 sm:p-5">
        <p class="mb-1 text-[11px] font-bold uppercase tracking-[.14em] text-indigo-600">{{ $product->category?->name ?? 'Groceries' }}</p>
        <h3 class="line-clamp-2 min-h-12 text-base font-extrabold leading-6 text-slate-900"><a href="{{ route('store.product', $product->slug) }}">{{ $product->name }}</a></h3>
        <div class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1"><strong class="text-lg text-slate-950">{{ $product->branchPrices->first()?->currency ?? 'MVR' }} {{ number_format($price, 2) }}</strong>@if($price < $regularPrice)<del class="text-xs text-slate-400">{{ number_format($regularPrice, 2) }}</del>@endif<span class="text-[10px] font-semibold text-slate-400">{{ $product->is_taxable ? 'GST inclusive' : 'GST exempt' }}</span></div>
        <p class="mt-2 text-xs font-semibold {{ $stock > 0 ? ($stock <= (float) $product->minimum_stock ? 'text-amber-600' : 'text-emerald-600') : 'text-rose-600' }}">{{ $stock > 0 ? ($stock <= (float) $product->minimum_stock ? 'Low stock' : 'In stock') : 'Out of stock' }}</p>
        <div class="mt-auto grid grid-cols-[1fr_auto] gap-2 pt-4">
            <form method="POST" action="{{ route('store.cart.add') }}">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="quantity" value="1"><button class="store-button-primary w-full px-3 py-2.5 text-sm" @disabled($stock <= 0)>Add to Cart</button></form>
            <a href="{{ route('store.product', $product->slug) }}" class="grid h-11 w-11 place-items-center rounded-2xl border border-slate-200 text-slate-700 transition hover:border-indigo-300 hover:text-indigo-600" aria-label="View {{ $product->name }}">→</a>
        </div>
    </div>
</article>
