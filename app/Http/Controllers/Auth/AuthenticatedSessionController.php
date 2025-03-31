<?php

namespace App\Http\Controllers\Auth;

use App\Events\AdminLoggedInEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * @param  \App\Http\Requests\Auth\LoginRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(LoginRequest $request)
    {
        $request->ensureIsNotRateLimited();

        // First try email login
        if (Auth::attempt($request->only('email', 'password'), $request->filled('remember'))) {
            $request->session()->regenerate();

            if ($request->user()->user_type == "admin") {
                event(new AdminLoggedInEvent);
                return redirect(route('admin.dashboard'));
            }
            return redirect(route('user.dashboard'));
        }

        // If email login fails, try username
        $user = User::where('username', $request->email)->first();
        if ($user && Auth::attempt(['email' => $user->email, 'password' => $request->password], $request->filled('remember'))) {
            $request->session()->regenerate();

            if ($request->user()->user_type == "admin") {
                event(new AdminLoggedInEvent);
                return redirect(route('admin.dashboard'));
            }
            return redirect(route('user.dashboard'));
        }

        // If both attempts fail, increment rate limiter and throw error
        RateLimiter::hit($request->throttleKey());
        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * Destroy an authenticated session.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
