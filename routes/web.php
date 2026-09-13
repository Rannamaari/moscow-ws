<?php

use App\Http\Controllers\AdminSaleReceiptController;
use App\Http\Controllers\CashierShiftReportController;
use App\Http\Controllers\PosApiController;
use App\Http\Controllers\PosPageController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\CustomerRegistrationController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\ShopController;
use App\Models\Category;
use App\Services\StorefrontCatalog;
use App\Services\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('store.home');
Route::get('/shop', [ShopController::class, 'index'])->name('store.shop');
Route::get('/categories/{slug}', [ShopController::class, 'category'])->name('store.category');
Route::get('/products/{slug}', ProductController::class)->name('store.product');
Route::get('/cart', [CartController::class, 'index'])->name('store.cart');
Route::post('/cart', [CartController::class, 'store'])->name('store.cart.add');
Route::patch('/cart/{productId}', [CartController::class, 'update'])->name('store.cart.update');
Route::delete('/cart/{productId}', [CartController::class, 'destroy'])->name('store.cart.remove');
Route::get('/checkout', [CheckoutController::class, 'create'])->name('store.checkout');
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('store.checkout.place');
Route::get('/register', [CustomerRegistrationController::class, 'show'])->name('store.register');
Route::post('/register/otp', [CustomerRegistrationController::class, 'requestOtp'])->middleware('throttle:3,1')->name('store.register.otp');
Route::post('/register/verify', [CustomerRegistrationController::class, 'verify'])->middleware('throttle:10,1')->name('store.register.verify');
Route::post('/customer/logout', [CustomerRegistrationController::class, 'logout'])->name('store.customer.logout');
Route::get('/order/{token}', OrderController::class)->name('store.order');
Route::view('/contact', 'storefront.contact')->name('store.contact');
Route::get('/sitemap.xml', function (StorefrontCatalog $catalog) {
    $products = $catalog->query()->get(['products.id', 'products.slug', 'products.name', 'products.images', 'products.updated_at']);
    $company = app(StorefrontContext::class)->company();
    $categories = Category::query()
        ->where('company_id', $company->id)
        ->where('is_active', true)
        ->whereHas('products', fn ($query) => $query->visibleOnline())
        ->orderBy('name')
        ->get(['id', 'slug', 'updated_at']);

    return response()->view('storefront.sitemap', compact('products', 'categories'))->header('Content-Type', 'application/xml');
})->name('store.sitemap');

Route::post('/locale/{locale}', function (Request $request, string $locale) {
    abort_unless(in_array($locale, ['en', 'dv'], true), 404);
    $request->session()->put('locale', $locale);

    return back();
})->name('locale.switch');

Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors([
                    'email' => 'The provided credentials do not match our records.',
                ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('pos.index'));
    })->name('login.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::get('/pos', PosPageController::class)->name('pos.index');
    Route::get('/admin/sales/{sale}/receipt/{format}', AdminSaleReceiptController::class)->name('admin.sales.receipt');
    Route::get('/reports/cashier-shifts/{cashierShift}/print', CashierShiftReportController::class)->name('cashier-shifts.print');

    Route::prefix('/pos/api')->group(function (): void {
        Route::get('/csrf-token', [PosApiController::class, 'csrfToken'])->name('pos.csrf-token');
        Route::get('/products/search', [PosApiController::class, 'searchProducts'])->name('pos.products.search');
        Route::get('/products/barcode/{barcode}', [PosApiController::class, 'barcodeLookup'])->name('pos.products.barcode');
        Route::post('/shifts/open', [PosApiController::class, 'openShift'])->name('pos.shifts.open');
        Route::post('/shifts/{cashierShift}/close', [PosApiController::class, 'closeShift'])->name('pos.shifts.close');

        Route::get('/customers/search', [PosApiController::class, 'searchCustomers'])->name('pos.customers.search');
        Route::get('/customers/{customer}/statement', [PosApiController::class, 'customerStatement'])->name('pos.customers.statement');
        Route::post('/customers', [PosApiController::class, 'createCustomer'])->name('pos.customers.store');

        Route::get('/held-sales', [PosApiController::class, 'heldSales'])->name('pos.held-sales.index');
        Route::get('/sales', [PosApiController::class, 'listSales'])->name('pos.sales.index');
        Route::get('/sales/search', [PosApiController::class, 'searchSales'])->name('pos.sales.search');
        Route::get('/sales/{sale}/resume', [PosApiController::class, 'resumeHeldSale'])->name('pos.sales.resume');
        Route::get('/sales/{sale}', [PosApiController::class, 'showSale'])->name('pos.sales.show');
        Route::post('/sales', [PosApiController::class, 'completeSale'])->name('pos.sales.store');
        Route::post('/sales/hold', [PosApiController::class, 'holdSale'])->name('pos.sales.hold');
        Route::post('/sales/{sale}/hold', [PosApiController::class, 'reholdSale'])->name('pos.sales.rehold');
        Route::post('/sales/{sale}/complete', [PosApiController::class, 'completeHeldSale'])->name('pos.sales.complete');
        Route::post('/sales/{sale}/cancel-held', [PosApiController::class, 'cancelHeldSale'])->name('pos.sales.cancel-held');
        Route::post('/sales/{sale}/returns', [PosApiController::class, 'returnSale'])->name('pos.sales.return');
        Route::post('/sales/{sale}/payments', [PosApiController::class, 'receiveCustomerPayment'])->name('pos.sales.payments.store');
    });
});
