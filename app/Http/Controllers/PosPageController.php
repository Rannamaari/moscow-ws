<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CashierShiftService;
use App\Support\PosUserContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PosPageController extends Controller
{
    public function __construct(
        private readonly PosUserContextResolver $posUserContextResolver,
        private readonly CashierShiftService $cashierShiftService,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        abort_unless($user && $user->can('sales.view') && $user->can('sales.create'), 403);
        $context = $this->posUserContextResolver->resolve($user);

        $walkInCustomer = Customer::query()
            ->where('company_id', $context['company_id'])
            ->where('is_walk_in', true)
            ->first();

        return view('pos', [
            'posBootstrap' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                    'can_access_admin' => $user->hasAnyRole(['super-admin', 'admin', 'manager']),
                ],
                'locale' => app()->getLocale(),
                'translations' => trans('pos'),
                'company' => [
                    'id' => $user->company?->id,
                    'name' => $user->company?->name,
                    'currency' => $context['branch']->currency,
                    'pos_test_mode' => (bool) $user->company?->pos_test_mode,
                ],
                'branch' => [
                    'id' => $context['branch']->id,
                    'name' => $context['branch']->name,
                    'code' => $context['branch']->code,
                ],
                'warehouse' => [
                    'id' => $context['warehouse']->id,
                    'name' => $context['warehouse']->name,
                    'code' => $context['warehouse']->code,
                ],
                'active_shift' => ($shift = $this->cashierShiftService->activeFor($context, $user->id)) ? [
                    'id' => $shift->id,
                    'shift_number' => $shift->shift_number,
                    'currency' => $shift->currency,
                    'opening_cash' => $shift->opening_cash,
                    'opened_at' => $shift->opened_at?->timezone(config('app.business_timezone'))->toIso8601String(),
                ] : null,
                'walk_in_customer' => $walkInCustomer ? [
                    'id' => $walkInCustomer->id,
                    'code' => $walkInCustomer->code,
                    'name' => $walkInCustomer->name,
                    'phone' => $walkInCustomer->phone,
                    'balance' => number_format((float) $walkInCustomer->transactions()->sum('amount'), 4, '.', ''),
                    'credit_limit' => $walkInCustomer->credit_limit,
                    'is_walk_in' => true,
                ] : null,
            ],
        ]);
    }
}
