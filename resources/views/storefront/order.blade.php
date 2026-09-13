@php
    $analyticsPurchase = ['transaction_id'=>$order->sale_number,'currency'=>$order->currency,'value'=>(float)$order->grand_total,'tax'=>(float)$order->tax_total,'shipping'=>(float)$order->delivery_charge,'items'=>$order->items->map(fn($item)=>['item_id'=>$item->product?->sku ?? $item->product_id,'item_name'=>$item->description,'price'=>(float)$item->unit_price,'quantity'=>(float)$item->quantity])->values()->all()];
@endphp
@extends('layouts.storefront')
@section('title', 'Order '.$order->sale_number.' | Moscow Traders Wholesale')
@section('robots', 'noindex, nofollow')
@if(session('order_placed') && config('services.google.analytics_measurement_id'))
@push('scripts')
<script>
if (typeof gtag === 'function') {
    gtag('event', 'purchase', {{ \Illuminate\Support\Js::from($analyticsPurchase) }});
}
</script>
@endpush
@endif
@section('content')
<section class="store-container max-w-4xl pt-12 sm:pt-16"><div class="rounded-[2rem] border border-slate-200 bg-white p-7 shadow-sm sm:p-10"><span class="grid h-14 w-14 place-items-center rounded-2xl bg-emerald-100 text-2xl text-emerald-700">✓</span><p class="store-kicker mt-6">{{ session('order_placed') ? 'Order received' : 'Order tracking' }}</p><h1 class="mt-2 text-3xl font-black tracking-tight sm:text-5xl">Thank you, {{ $order->customer->name }}.</h1><p class="mt-4 max-w-2xl leading-7 text-slate-600">Your order is now in our system. Moscow Traders Wholesale will contact you using <strong>{{ $order->customer->phone }}</strong> to confirm the next step.</p>
<div class="mt-8 grid gap-4 rounded-3xl bg-slate-50 p-5 sm:grid-cols-3"><div><small class="store-meta-label">Reference</small><strong class="block">{{ $order->sale_number }}</strong></div><div><small class="store-meta-label">Order status</small><strong class="block capitalize">{{ $order->order_status }}</strong></div><div><small class="store-meta-label">Payment</small><strong class="block capitalize">{{ str_replace('_',' ',$order->payment_status) }}</strong></div></div>
<div class="mt-8"><h2 class="text-lg font-black">Items</h2><div class="mt-4 divide-y divide-slate-100">@foreach($order->items as $item)<div class="flex justify-between gap-4 py-4 text-sm"><span>{{ $item->description }} × {{ (float)$item->quantity }}</span><strong>{{ $order->currency }} {{ number_format((float)$item->line_total,2) }}</strong></div>@endforeach<div class="flex justify-between py-5 text-lg"><strong>Total</strong><strong>{{ $order->currency }} {{ number_format((float)$order->grand_total,2) }}</strong></div></div></div>
@if(session('order_placed') && ! $order->customer->phone_verified_at)<div class="mt-7 rounded-2xl border border-indigo-200 bg-indigo-50 p-5"><h2 class="text-lg font-black text-slate-950">Your customer account is ready</h2><p class="mt-2 text-sm leading-6 text-slate-600">Verify {{ $order->customer->phone }} by SMS to activate your account and sign in.</p><a href="{{ route('store.register', ['redirect' => 'order']) }}" class="store-button-primary mt-4">Verify and activate account</a></div>@endif
<div class="mt-7 flex flex-wrap gap-3"><a href="{{ route('store.shop') }}" class="store-button-primary">Continue Shopping</a><a href="{{ route('store.contact') }}" class="store-button-outline">Contact Us</a></div></div></section>
@endsection
