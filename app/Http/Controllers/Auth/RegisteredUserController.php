<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Tenancy\Actions\CreateTenantForUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request, CreateTenantForUser $createTenant): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $createTenant): User {
            $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));
            $createTenant->handle($user, $request->string('organization_name')->toString());

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
