@extends('layouts.storefront')
@section('title', 'Customer Account | Moscow Traders Wholesale')
@section('content')
<section class="store-container max-w-2xl pt-10 sm:pt-14">
    <span class="store-kicker">Customer account</span>
    @if($customer)
        <div class="mt-3 rounded-[2rem] border border-emerald-200 bg-white p-7 shadow-sm sm:p-10">
            <h1 class="text-3xl font-black tracking-tight">Welcome, {{ $customer->name }}</h1>
            <p class="mt-3 text-slate-600">Your verified phone number is <strong>{{ $customer->phone }}</strong>.</p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a class="store-button-primary px-6 py-3" href="{{ route('store.shop') }}">Continue shopping</a>
                <form method="POST" action="{{ route('store.customer.logout') }}">@csrf<button class="rounded-full border border-slate-300 px-6 py-3 text-sm font-bold">Sign out</button></form>
            </div>
        </div>
    @elseif(session('store_customer_otp_challenge'))
        <div class="mt-3 rounded-[2rem] border border-slate-200 bg-white p-7 shadow-sm sm:p-10">
            <h1 class="text-3xl font-black tracking-tight">Enter your verification code</h1>
            <p class="mt-3 text-slate-600">We sent a six-digit code to {{ data_get(session('store_customer_pending'), 'phone') }}. It expires in five minutes.</p>
            @if(session('store_customer_otp_debug'))<p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-bold text-amber-800">Dry-run code: {{ session('store_customer_otp_debug') }}</p>@endif
            <form class="mt-7 space-y-5" method="POST" action="{{ route('store.register.verify') }}">@csrf
                <label class="store-label block">Verification code<input class="store-input mt-1.5 text-center text-2xl tracking-[.35em]" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label>
                <button class="store-button-primary w-full justify-center py-3.5">Verify and continue</button>
            </form>
            <a class="mt-4 inline-block text-sm font-bold text-indigo-600" href="{{ route('store.register', ['restart' => 1]) }}">Change registration details</a>
        </div>
    @else
        <div class="mt-3 rounded-[2rem] border border-slate-200 bg-white p-7 shadow-sm sm:p-10">
            <h1 class="text-3xl font-black tracking-tight">Register or sign in by phone</h1>
            <p class="mt-3 text-slate-600">We’ll send a one-time verification code to your Maldives mobile number. No password is needed.</p>
            <form class="mt-7 space-y-5" method="POST" action="{{ route('store.register.otp') }}">@csrf
                <label class="store-label block">Full name <span class="font-normal text-slate-400">(new accounts only)</span><input class="store-input mt-1.5" name="name" value="{{ old('name', data_get(session('store_customer_registration_prefill'), 'name')) }}"></label>
                <label class="store-label block">Phone number<input class="store-input mt-1.5" type="tel" name="phone" value="{{ old('phone', data_get(session('store_customer_registration_prefill'), 'phone')) }}" placeholder="7779493" required></label>
                <button class="store-button-primary w-full justify-center py-3.5">Send verification code</button>
            </form>
        </div>
    @endif
</section>
@endsection
