@php
    $catalog = app(\App\Services\StorefrontCatalog::class);
    $price = $catalog->price($product); $regular = $catalog->regularPrice($product); $stock = $catalog->available($product);
    $images = collect($product->images ?? []); $mainImage = $images->first(); $mainUrl = $mainImage ? \Illuminate\Support\Facades\Storage::disk('public')->url($mainImage) : null;
    $metaDescription = $product->short_description ?: Str::limit(strip_tags($product->description ?: "Buy {$product->name} from Moscow Traders Wholesale."), 155);
    $analyticsViewItem = ['currency'=>$product->branchPrices->first()?->currency ?? 'MVR','value'=>$price,'items'=>[['item_id'=>$product->sku,'item_name'=>$product->name,'item_brand'=>$product->brand?->name,'item_category'=>$product->category?->name,'price'=>$price]]];
@endphp
@extends('layouts.storefront')
@section('title', $product->name.' | Moscow Traders Wholesale')
@section('description', $metaDescription)
@section('canonical', route('store.product', $product->slug))
@section('og_type', 'product')
@section('social_image', $mainUrl ?: '')
@push('head')
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@graph'=>[['@type'=>'Product','@id'=>route('store.product',$product->slug).'#product','name'=>$product->name,'description'=>$metaDescription,'sku'=>$product->sku,'category'=>$product->category?->name,'image'=>$images->map(fn($image)=>\Illuminate\Support\Facades\Storage::disk('public')->url($image))->values()->all(),'brand'=>$product->brand ? ['@type'=>'Brand','name'=>$product->brand->name] : null,'offers'=>['@type'=>'Offer','url'=>route('store.product',$product->slug),'priceCurrency'=>$product->branchPrices->first()?->currency ?? 'MVR','price'=>number_format($price,2,'.',''),'priceSpecification'=>['@type'=>'UnitPriceSpecification','price'=>number_format($price,2,'.',''),'priceCurrency'=>$product->branchPrices->first()?->currency ?? 'MVR','valueAddedTaxIncluded'=>$product->is_taxable],'availability'=>$stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock','itemCondition'=>'https://schema.org/NewCondition','seller'=>['@id'=>route('store.home').'#store']]],['@type'=>'BreadcrumbList','itemListElement'=>collect([['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>route('store.home')],['@type'=>'ListItem','position'=>2,'name'=>$product->category?->name ?? 'Shop','item'=>$product->category ? route('store.category',$product->category->slug) : route('store.shop')],['@type'=>'ListItem','position'=>3,'name'=>$product->name,'item'=>route('store.product',$product->slug)]])->all()]]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) !!}</script>
@endpush
@if(config('services.google.analytics_measurement_id'))
@push('scripts')
<script>
if (typeof gtag === 'function') {
    gtag('event', 'view_item', {{ \Illuminate\Support\Js::from($analyticsViewItem) }});
}
</script>
@endpush
@endif
@section('content')
<section class="store-container pt-8 sm:pt-12"><nav class="mb-7 text-sm text-slate-500"><a href="{{ route('store.shop') }}">Shop</a> <span class="mx-2">/</span> @if($product->category)<a href="{{ route('store.category', $product->category->slug) }}">{{ $product->category->name }}</a><span class="mx-2">/</span>@endif <span class="text-slate-800">{{ $product->name }}</span></nav>
<div class="grid gap-9 lg:grid-cols-2 lg:gap-14">
    <div><div class="aspect-square overflow-hidden rounded-[2rem] border border-slate-200 bg-gradient-to-br from-white to-indigo-50">@if($mainUrl)<img src="{{ $mainUrl }}" width="900" height="900" alt="{{ $product->name }}" class="h-full w-full object-cover">@else<span class="store-product-placeholder h-full"><svg viewBox="0 0 120 120" fill="none"><rect x="34" y="18" width="52" height="84" rx="12" stroke="currentColor" stroke-width="5"/><path d="M48 30h24M53 89h14" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg></span>@endif</div>@if($images->count() > 1)<div class="mt-3 grid grid-cols-5 gap-3">@foreach($images as $image)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image) }}" width="160" height="160" loading="lazy" alt="{{ $product->name }}" class="aspect-square rounded-2xl border border-slate-200 object-cover">@endforeach</div>@endif</div>
    <div class="lg:py-5"><p class="store-kicker">{{ $product->category?->name ?? 'Groceries' }}</p><h1 class="mt-3 text-4xl font-black tracking-tight sm:text-5xl">{{ $product->name }}</h1>@if($product->short_description)<p class="mt-4 text-lg leading-8 text-slate-600">{{ $product->short_description }}</p>@endif
        <div class="mt-6 flex flex-wrap items-baseline gap-x-3 gap-y-1"><strong class="text-3xl font-black">{{ $product->branchPrices->first()?->currency ?? 'MVR' }} {{ number_format($price, 2) }}</strong>@if($price < $regular)<del class="text-lg text-slate-400">{{ number_format($regular, 2) }}</del>@endif<span class="text-xs font-semibold text-slate-400">{{ $product->is_taxable ? 'GST inclusive' : 'GST exempt' }}</span></div>
        <div class="mt-5 flex flex-wrap gap-2 text-sm"><span class="rounded-full bg-slate-100 px-4 py-2">SKU: <strong>{{ $product->sku }}</strong></span>@if($product->brand)<span class="rounded-full bg-slate-100 px-4 py-2">Brand: <strong>{{ $product->brand->name }}</strong></span>@endif<span class="rounded-full px-4 py-2 font-bold {{ $stock > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $stock > 0 ? ($stock <= (float)$product->minimum_stock ? 'Low Stock' : 'In Stock') : 'Out of Stock' }}</span></div>
        <form method="POST" action="{{ route('store.cart.add') }}" class="mt-8 flex gap-3">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><label><span class="sr-only">Quantity</span><input type="number" name="quantity" value="1" min="1" max="{{ $stock === PHP_FLOAT_MAX ? 999 : (int)$stock }}" class="store-input w-24 text-center"></label><button class="store-button-primary flex-1 justify-center py-3.5" @disabled($stock <= 0)>Add to Cart</button></form>
        @if($product->description)<div class="mt-9 border-t border-slate-200 pt-7"><h2 class="text-lg font-black">Product details</h2><div class="store-rich-text mt-4">{{ \Filament\Forms\Components\RichEditor\RichContentRenderer::make($product->description) }}</div></div>@endif
    </div>
</div></section>
@if($related->isNotEmpty())<section class="store-section store-container"><div class="store-section-heading"><div><span class="store-kicker">You may also like</span><h2>Related Products</h2></div></div><div class="store-product-grid">@foreach($related as $item)<x-storefront.product-card :product="$item" />@endforeach</div></section>@endif
@endsection
