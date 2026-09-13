<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Services\DhiraaguSmsService;
use App\Services\MaldivesPhoneNormalizer;
use App\Services\StorefrontContext;
use App\Services\StorefrontCustomerSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerRegistrationController extends Controller
{
    public function show(Request $request, StorefrontContext $context, StorefrontCustomerSession $customerSession): View
    {
        if ($request->query('redirect') === 'checkout') {
            $request->session()->put('store_customer_intended', 'checkout');
        } elseif ($request->query('redirect') === 'order' && $request->session()->has('store_customer_last_order')) {
            $request->session()->put('store_customer_intended', 'order');
        } elseif (! $request->session()->has('store_customer_otp_challenge')) {
            $request->session()->forget('store_customer_intended');
        }

        if ($request->boolean('restart')) {
            $request->session()->forget(['store_customer_otp_challenge', 'store_customer_pending', 'store_customer_otp_debug']);
        }

        $customer = $customerSession->customer($request, $context->company());

        return view('storefront.register', compact('customer'));
    }

    public function requestOtp(Request $request, StorefrontContext $context, MaldivesPhoneNormalizer $normalizer, DhiraaguSmsService $sms): RedirectResponse
    {
        if (app()->isProduction() && config('services.dhiraagu_sms.dry_run')) {
            return back()->withInput()->withErrors(['phone' => 'SMS registration is temporarily unavailable. Please try again later.']);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
        ]);
        $phone = $normalizer->normalize($data['phone']);

        if ($phone === null) {
            return back()->withInput()->withErrors(['phone' => 'Enter a valid 7-digit Maldives phone number.']);
        }

        $company = $context->company();
        $existingCustomer = Customer::query()
            ->where('company_id', $company->id)
            ->where('phone', $phone)
            ->where('is_walk_in', false)
            ->first();

        if (! $existingCustomer && blank($data['name'] ?? null)) {
            return back()->withInput()->withErrors(['name' => 'Enter your full name to create a new account.']);
        }

        $code = (string) random_int(100000, 999999);
        $challenge = CustomerOtpChallenge::query()->create([
            'company_id' => $company->id,
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => 'registration',
            'expires_at' => now()->addMinutes(5),
            'request_ip' => $request->ip(),
        ]);
        $result = $sms->send($phone, "Your Moscow Trade verification code is {$code}. It expires in 5 minutes.", $company->id, null, 'customer_otp');

        if (! $result['successful']) {
            $challenge->delete();

            return back()->withInput()->withErrors(['phone' => 'We could not send the verification code. Please try again.']);
        }

        $request->session()->put([
            'store_customer_otp_challenge' => $challenge->id,
            'store_customer_pending' => ['name' => $existingCustomer?->name ?? $data['name'], 'phone' => $phone],
        ]);

        if (config('services.dhiraagu_sms.dry_run')) {
            $request->session()->put('store_customer_otp_debug', $code);
        }

        return redirect()->route('store.register')->with('success', 'Verification code sent.');
    }

    public function verify(Request $request, StorefrontContext $context): RedirectResponse
    {
        $data = $request->validate(['otp' => ['required', 'digits:6']]);
        $pending = $request->session()->get('store_customer_pending', []);
        $outcome = DB::transaction(function () use ($request, $context, $data, $pending): array {
            $challenge = CustomerOtpChallenge::query()
                ->whereKey($request->session()->get('store_customer_otp_challenge'))
                ->where('company_id', $context->company()->id)
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->consumed_at || $challenge->expires_at->isPast()) {
                return ['error' => 'This code has expired. Request a new one.'];
            }

            if ($challenge->attempts >= 5) {
                return ['error' => 'Too many attempts. Request a new code.'];
            }

            $challenge->increment('attempts');

            if (! Hash::check($data['otp'], $challenge->code_hash)) {
                return ['error' => 'The verification code is incorrect.'];
            }

            if (($pending['phone'] ?? null) !== $challenge->phone) {
                return ['error' => 'Registration details are no longer valid.'];
            }

            $customer = Customer::query()->updateOrCreate(
                ['company_id' => $challenge->company_id, 'phone' => $challenge->phone, 'is_walk_in' => false],
                [
                    'code' => Customer::query()->where('company_id', $challenge->company_id)->where('phone', $challenge->phone)->value('code') ?: 'WEB-'.Str::upper(Str::random(10)),
                    'name' => $pending['name'],
                    'city' => $company->city,
                    'opening_balance' => 0,
                    'is_active' => true,
                    'phone_verified_at' => now(),
                ],
            );

            $challenge->update(['verified_at' => now(), 'consumed_at' => now()]);

            return ['customer' => $customer];
        });

        if (isset($outcome['error'])) {
            return back()->withErrors(['otp' => $outcome['error']]);
        }

        $customer = $outcome['customer'];
        $request->session()->regenerate();
        $request->session()->put('store_customer_id', $customer->id);
        $request->session()->forget(['store_customer_otp_challenge', 'store_customer_pending', 'store_customer_otp_debug']);

        $destination = $request->session()->pull('store_customer_intended');
        $orderToken = $destination === 'order' ? $request->session()->pull('store_customer_last_order') : null;
        $request->session()->forget('store_customer_registration_prefill');

        if ($orderToken) {
            return redirect()->route('store.order', $orderToken)->with('success', 'Your phone number is verified and you are signed in.');
        }

        return redirect()->route($destination === 'checkout' ? 'store.checkout' : 'store.register')->with('success', 'Your phone number is verified.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('store_customer_id');
        $request->session()->regenerateToken();

        return redirect()->route('store.home')->with('success', 'You are signed out.');
    }
}
