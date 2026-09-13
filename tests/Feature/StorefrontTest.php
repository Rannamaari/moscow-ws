<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Sale;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_only_shows_featured_online_products(): void
    {
        $this->seed(DatabaseSeeder::class);
        $online = Product::query()->where('sku', 'XL-BIG-250')->firstOrFail();
        $offline = Product::query()->where('sku', 'WATER-5L-CASE')->firstOrFail();
        $offline->update(['show_online' => false]);

        $this->get(route('store.home'))->assertOk()->assertSee($online->name)->assertSee('GST inclusive')->assertSee('images/moscow logo.png', false)->assertDontSee($offline->name)->assertDontSee('New Arrivals')->assertDontSee('Popular Products');
    }

    public function test_product_page_renders_a_formatted_and_sanitized_description(): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = Product::query()->where('sku', 'GALAXY-A16')->firstOrFail();
        $product->update([
            'description' => '<h2>Built for island life</h2><ul><li><strong>Fast</strong> and dependable</li></ul><script>alert("unsafe")</script>',
        ]);

        $this->get(route('store.product', $product->slug))
            ->assertOk()
            ->assertSee('class="store-rich-text mt-4"', false)
            ->assertSee('<h2>Built for island life</h2>', false)
            ->assertSee('<ul><li><strong>Fast</strong> and dependable</li></ul>', false)
            ->assertDontSee('<script>alert("unsafe")</script>', false)
            ->assertDontSee('alert("unsafe")', false);
    }

    public function test_guest_checkout_creates_a_website_sale_and_uses_shared_inventory(): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = Product::query()->where('sku', 'GALAXY-A16')->firstOrFail();
        $product->update([
            'selling_price' => 100,
            'sale_price' => null,
            'tax_rate' => 8,
        ]);
        $product->branchPrices()->update(['selling_price' => 100]);
        $balance = InventoryBalance::query()->where('product_id', $product->id)->firstOrFail();
        $before = (float) $balance->quantity;

        $this->post(route('store.cart.add'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->get(route('store.cart'))
            ->assertOk()
            ->assertSee('MVR 108.00')
            ->assertSee('MVR 216.00')
            ->assertSee('Prices include GST.');
        $this->get(route('store.checkout'))
            ->assertOk()
            ->assertSee('Log in / Register')
            ->assertSee('Continue as guest')
            ->assertSee('activate your new account after ordering')
            ->assertDontSee('without creating an account')
            ->assertDontSee('name="delivery_method"', false)
            ->assertSee('MVR 216.00')
            ->assertSee('Prices include GST.');
        $this->get(route('store.checkout', ['guest' => 1]))
            ->assertOk()
            ->assertSee('Checking out as a guest')
            ->assertSee('name="delivery_method"', false)
            ->assertSee('MVR 216.00')
            ->assertSee('Prices include GST.');
        $response = $this->post(route('store.checkout.place'), [
            'name' => 'Web Customer',
            'phone' => '7771234',
            'email' => 'customer@example.com',
            'delivery_method' => 'pickup',
            'payment_method' => 'cash',
            'notes' => 'Call before collection',
        ]);
        $response->assertSessionHasNoErrors();
        $response->assertRedirectContains('/order/');

        $order = Sale::query()->where('sales_channel', 'website')->firstOrFail();
        $response->assertRedirect(route('store.order', $order->tracking_token));
        $this->assertSame('pending', $order->order_status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('Web Customer', $order->customer->name);
        $this->assertCount(1, $order->items);
        $this->assertSame('200.0000', $order->subtotal);
        $this->assertSame('16.0000', $order->tax_total);
        $this->assertSame('216.0000', $order->grand_total);
        $this->assertSame('100.0000', $order->items->first()->unit_price);
        $this->assertSame($before - 2, (float) $balance->fresh()->quantity);
        $this->assertNull($order->customer->phone_verified_at);
        $response->assertSessionHas('store_customer_registration_prefill', [
            'name' => 'Web Customer',
            'phone' => '9607771234',
        ]);
        $this->get(route('store.order', $order->tracking_token))
            ->assertOk()
            ->assertSee($order->sale_number)
            ->assertSee('Your customer account is ready')
            ->assertSee('Verify and activate account');
    }

    public function test_guest_checkout_cannot_overwrite_a_verified_customer_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = Product::query()->where('sku', 'GALAXY-A16')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $product->company_id,
            'code' => 'WEB-VERIFIED',
            'name' => 'Verified Customer',
            'phone' => '9607779493',
            'phone_verified_at' => now(),
            'opening_balance' => 0,
            'is_active' => true,
            'is_walk_in' => false,
        ]);
        $this->post(route('store.cart.add'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->from(route('store.checkout', ['guest' => 1]))->post(route('store.checkout.place'), [
            'name' => 'Someone Else',
            'phone' => '7779493',
            'delivery_method' => 'pickup',
            'payment_method' => 'cash',
        ])->assertRedirect(route('store.checkout', ['guest' => 1]))
            ->assertSessionHasErrors('phone');

        $this->assertSame('Verified Customer', $customer->fresh()->name);
        $this->assertSame(0, Sale::query()->where('sales_channel', 'website')->count());
    }
}
