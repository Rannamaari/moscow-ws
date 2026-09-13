@extends('layouts.storefront')

@section('title', 'Checkout | Moscow Trade')

@section('content')
<section class="store-container pt-10 sm:pt-14">
    <span class="store-kicker">Simple and secure</span>
    <h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Checkout</h1>

    @unless($showCheckoutForm)
        <div class="mt-9 grid items-start gap-8 lg:grid-cols-[1fr_380px]">
            <div class="store-checkout-panel">
                <h2>How would you like to checkout?</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">Log in to use your verified customer details, or continue as a guest and activate your new account after ordering.</p>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5">
                        <h3 class="text-lg font-black text-slate-950">Log in or register</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Use your phone number and a one-time SMS verification code.</p>
                        <a class="store-button-primary mt-5 w-full justify-center" href="{{ route('store.register', ['redirect' => 'checkout']) }}">Log in / Register</a>
                    </div>

                    <div class="rounded-2xl border border-slate-200 p-5">
                        <h3 class="text-lg font-black text-slate-950">Checkout as guest</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Enter your contact and delivery details. We’ll create an account you can activate by SMS after ordering.</p>
                        <a class="mt-5 inline-flex w-full items-center justify-center rounded-full border border-slate-300 px-5 py-3 text-sm font-black text-slate-900 transition hover:border-slate-950" href="{{ route('store.checkout', ['guest' => 1]) }}">Continue as guest</a>
                    </div>
                </div>
            </div>

            <aside class="rounded-3xl bg-slate-950 p-7 text-white lg:sticky lg:top-28">
                <h2 class="text-xl font-black">Your order</h2>
                <div class="mt-5 grid gap-4">
                    @foreach($items as $item)
                        <div class="flex justify-between gap-5 text-sm">
                            <span class="text-slate-300">{{ $item['product']->name }} × {{ $item['quantity'] }}</span>
                            <strong>{{ number_format($item['price'] * $item['quantity'], 2) }}</strong>
                        </div>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-between border-t border-white/10 pt-5">
                    <span>Total</span>
                    <strong class="text-xl">{{ $items->first()['product']->branchPrices->first()?->currency ?? 'MVR' }} {{ number_format($subtotal, 2) }}</strong>
                </div>
                <p class="mt-3 text-xs font-semibold text-slate-400">Prices include GST where applicable.</p>
            </aside>
        </div>
    @else
        <form method="POST" action="{{ route('store.checkout.place') }}" class="mt-9 grid items-start gap-8 lg:grid-cols-[1fr_380px]">
            @csrf
            <div class="grid gap-6">
                <section class="store-checkout-panel">
                    <h2>Contact details</h2>
                    @if($registeredCustomer)
                        <p class="mt-2 text-sm text-emerald-700">Using your verified customer profile.</p>
                    @else
                        <p class="mt-2 text-sm text-slate-500">Checking out as a guest. You can still <a class="font-bold text-indigo-600" href="{{ route('store.register', ['redirect' => 'checkout']) }}">log in or register</a>.</p>
                    @endif
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="store-label">Full name<input class="store-input mt-1.5" name="name" value="{{ old('name', $registeredCustomer?->name) }}" required></label>
                        <label class="store-label">Phone number<input class="store-input mt-1.5" type="tel" name="phone" value="{{ old('phone', $registeredCustomer?->phone) }}" @readonly($registeredCustomer) required></label>
                        <label class="store-label sm:col-span-2">Email <span class="font-normal text-slate-400">(optional)</span><input class="store-input mt-1.5" type="email" name="email" value="{{ old('email', $registeredCustomer?->email) }}"></label>
                    </div>
                </section>

                <section class="store-checkout-panel">
                    <h2>Delivery or pickup</h2>
                    <div class="mt-5 grid gap-3">
                        @foreach($deliveryMethods as $key => $label)
                            <label class="store-choice">
                                <input type="radio" name="delivery_method" value="{{ $key }}" @checked(old('delivery_method', array_key_first($deliveryMethods)) === $key)>
                                <span><strong>{{ $label }}</strong>@if($key === 'pickup')<small>Collect from Moscow Trade.</small>@else<small>We will contact you to confirm delivery details.</small>@endif</span>
                            </label>
                        @endforeach
                    </div>
                    <label class="store-label mt-5 block">Address / location details<textarea class="store-input mt-1.5" name="delivery_address" rows="3">{{ old('delivery_address') }}</textarea></label>
                </section>

                <section class="store-checkout-panel">
                    <h2>Payment</h2>
                    <div class="mt-5 grid gap-3">
                        @foreach($paymentMethods as $key => $label)
                            <label class="store-choice">
                                <input type="radio" name="payment_method" value="{{ $key }}" @checked(old('payment_method', array_key_first($paymentMethods)) === $key)>
                                <span><strong>{{ $label }}</strong><small>No online payment will be taken on this website.</small></span>
                            </label>
                        @endforeach
                    </div>
                    <label class="store-label mt-5 block">Order notes <span class="font-normal text-slate-400">(optional)</span><textarea class="store-input mt-1.5" name="notes" rows="3">{{ old('notes') }}</textarea></label>
                </section>
            </div>

            <aside class="rounded-3xl bg-slate-950 p-7 text-white lg:sticky lg:top-28">
                <h2 class="text-xl font-black">Your order</h2>
                <div class="mt-5 grid gap-4">
                    @foreach($items as $item)
                        <div class="flex justify-between gap-5 text-sm">
                            <span class="text-slate-300">{{ $item['product']->name }} × {{ $item['quantity'] }}</span>
                            <strong>{{ number_format($item['price'] * $item['quantity'], 2) }}</strong>
                        </div>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-between border-t border-white/10 pt-5">
                    <span>Total</span>
                    <strong class="text-xl">{{ $items->first()['product']->branchPrices->first()?->currency ?? 'MVR' }} {{ number_format($subtotal, 2) }}</strong>
                </div>
                <p class="mt-3 text-xs font-semibold text-slate-400">Prices include GST where applicable.</p>
                <p class="mt-2 text-xs text-slate-400">Delivery charge: MVR 0.00. Any future charges can be configured before activation.</p>
                <button class="store-hero-button mt-6 w-full justify-center">Place Order</button>
            </aside>
        </form>
    @endunless
</section>
@endsection
