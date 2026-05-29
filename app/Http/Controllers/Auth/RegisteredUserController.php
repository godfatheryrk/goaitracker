<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * `name` is NOT collected from the user — it is derived from the email
     * local-part (portion before `@`) so the `users.name NOT NULL` column is
     * satisfied without altering the framework migration. The `password`
     * attribute relies on the model's `hashed` cast (see User::casts()).
     *
     * On success: log the user in via the `web` guard, regenerate the session
     * to defeat fixation, and redirect to `/dashboard`.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $email = $request->string('email')->toString();

        $user = User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => $request->string('password')->toString(),
        ]);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
