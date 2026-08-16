<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        $this->notifyOperators($user);

        // Auto-login is intentionally kept: the user is authenticated immediately
        // after registration. Verified-only areas stay protected because routes
        // like /dashboard apply the `verified` middleware, redirecting unverified
        // users to the verification notice (see RegistrationTest).
        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    /**
     * Notify configured operators that a new user registered.
     */
    private function notifyOperators(User $user): void
    {
        $emails = (array) config('services.admin_notify_emails', []);

        if ($emails === []) {
            return;
        }

        Notification::route('mail', $emails)
            ->notify(new NewUserRegistered($user->name, $user->email));
    }
}
