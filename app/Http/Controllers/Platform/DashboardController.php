<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'organizations' => Tenant::query()->count(),
                'activeOrganizations' => Tenant::query()->where('status', 'active')->count(),
                'users' => User::query()->where('is_platform_admin', false)->count(),
                'activeSubscriptions' => Tenant::query()->where('subscription_status', 'active')->count(),
                'products' => Product::query()->count(),
                'orders' => Order::query()->count(),
                'channels' => ChannelAccount::query()->count(),
                'plans' => SubscriptionPlan::query()->where('status', 'active')->count(),
            ],
            'recentTenants' => Tenant::query()->withCount('users')->latest()->limit(8)->get(),
        ]);
    }
}
