<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\StorefrontCatalog;
use App\Services\StorefrontContext;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(StorefrontContext $context, StorefrontCatalog $catalog): View
    {
        $company = $context->company();
        $categories = Category::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('products', fn ($query) => $query->visibleOnline())
            ->withCount(['products' => fn ($query) => $query->visibleOnline()])
            ->orderByDesc('products_count')
            ->limit(8)
            ->get();

        $featured = $catalog->query()
            ->where('is_featured', true)
            ->latest()
            ->limit(12)
            ->get();

        return view('storefront.home', compact('company', 'categories', 'featured'));
    }
}
