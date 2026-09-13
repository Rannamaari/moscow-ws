<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\SaleStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\TransactionException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\MaldivesPhoneNormalizer;
use App\Services\SalesService;
use App\Services\StorefrontCatalog;
use App\Services\StorefrontContext;
use App\Services\StorefrontCustomerSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function create(Request $request, StorefrontCatalog $catalog, StorefrontContext $context, StorefrontCustomerSession $customerSession): View|RedirectResponse
    {
        $summary = $this->cartSummary($catalog);

        if ($summary['items']->isEmpty()) {
            return redirect()->route('store.cart')->withErrors(['cart' => 'Your cart is empty.']);
        }

        $registeredCustomer = $customerSession->customer($request, $context->company());

        return view('storefront.checkout', [
            ...$summary,
            'company' => $context->company(),
            'deliveryMethods' => $context->deliveryMethods(),
            'paymentMethods' => $context->paymentMethods(),
            'registeredCustomer' => $registeredCustomer,
            'showCheckoutForm' => $registeredCustomer !== null || $request->boolean('guest'),
        ]);
    }

    public function store(Request $request, StorefrontCatalog $catalog, StorefrontContext $context, StorefrontCustomerSession $customerSession, MaldivesPhoneNormalizer $normalizer, SalesService $sales): RedirectResponse
    {
        $deliveryMethods = $context->deliveryMethods();
        $paymentMethods = $context->paymentMethods();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'delivery_method' => ['required', Rule::in(array_keys($deliveryMethods))],
            'delivery_address' => ['nullable', 'string', 'max:1000', 'required_if:delivery_method,local_delivery'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['required', Rule::in(array_keys($paymentMethods))],
        ]);

        $registeredCustomer = $customerSession->customer($request, $context->company());
        $normalizedPhone = $registeredCustomer?->phone ?: $normalizer->normalize($data['phone']);

        if ($normalizedPhone === null) {
            return back()->withInput()->withErrors(['phone' => 'Enter a valid 7-digit Maldives phone number.']);
        }

        $data['phone'] = $normalizedPhone;

        $summary = $this->cartSummary($catalog);

        if ($summary['items']->isEmpty()) {
            return redirect()->route('store.cart')->withErrors(['cart' => 'Your cart is empty.']);
        }

        foreach ($summary['items'] as $item) {
            if ($item['quantity'] > $catalog->available($item['product'])) {
                return redirect()->route('store.cart')->withErrors(['cart' => "{$item['product']->name} no longer has enough stock."]);
            }
        }

        $company = $context->company();
        $branch = $context->branch();
        $warehouse = $context->warehouse();
        $customer = $registeredCustomer ?: Customer::query()
            ->where('company_id', $company->id)
            ->where('is_walk_in', false)
            ->where('phone', $data['phone'])
            ->first();

        if (! $registeredCustomer && $customer?->phone_verified_at) {
            return back()->withInput()->withErrors([
                'phone' => 'This phone number already has an account. Log in with the SMS verification code to continue, or use another number.',
            ]);
        }

        if ($customer) {
            $customer->update([
                'name' => $data['name'],
                'email' => ($data['email'] ?? null) ?: $customer->email,
                'address' => ($data['delivery_address'] ?? null) ?: $customer->address,
                'city' => $company->city,
                'is_active' => true,
            ]);
        } else {
            $customer = Customer::query()->create([
                'company_id' => $company->id,
                'code' => 'WEB-'.Str::upper(Str::random(10)),
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'address' => $data['delivery_address'] ?? null,
                'city' => $company->city,
                'credit_limit' => null,
                'opening_balance' => 0,
                'is_active' => true,
                'is_walk_in' => false,
            ]);
        }

        try {
            $sale = $sales->createSale(
                $company->id,
                $branch->id,
                $warehouse->id,
                $summary['items']->map(fn ($item): array => [
                    'product_id' => $item['product']->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price_before_tax'],
                ])->all(),
                [],
                [
                    'customer_id' => $customer->id,
                    'status' => SaleStatus::Completed,
                    'sales_channel' => 'website',
                    'order_status' => 'pending',
                    'payment_status' => 'unpaid',
                    'website_payment_method' => $data['payment_method'],
                    'delivery_method' => $data['delivery_method'],
                    'delivery_charge' => 0,
                    'delivery_address' => $data['delivery_address'] ?? null,
                    'tracking_token' => Str::random(40),
                    'client_transaction_uuid' => (string) Str::uuid(),
                    'notes' => collect([
                        'Website order',
                        'Payment: '.$paymentMethods[$data['payment_method']],
                        'Delivery: '.$deliveryMethods[$data['delivery_method']],
                        $data['notes'] ?? null,
                    ])->filter()->implode("\n"),
                ],
            );
        } catch (InventoryException|TransactionException $exception) {
            return redirect()->route('store.cart')->withErrors(['cart' => $exception->getMessage()]);
        }

        session()->forget('store_cart');

        if (! $registeredCustomer && ! $customer->phone_verified_at) {
            session()->put([
                'store_customer_registration_prefill' => ['name' => $customer->name, 'phone' => $customer->phone],
                'store_customer_last_order' => $sale->tracking_token,
            ]);
        }

        return redirect()->route('store.order', $sale->tracking_token)->with('order_placed', true);
    }

    /** @return array{items: Collection, subtotal: float} */
    private function cartSummary(StorefrontCatalog $catalog): array
    {
        $cart = session('store_cart', []);
        $products = $catalog->query()->whereIn('id', array_keys($cart))->get()->keyBy('id');
        $items = collect($cart)->map(function ($quantity, $productId) use ($products, $catalog) {
            $product = $products->get($productId);

            return $product ? [
                'product' => $product,
                'quantity' => (int) $quantity,
                'price' => $catalog->price($product),
                'price_before_tax' => $catalog->priceBeforeTax($product),
            ] : null;
        })->filter()->values();

        return ['items' => $items, 'subtotal' => $items->sum(fn ($item): float => $item['price'] * $item['quantity'])];
    }
}
