<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        $userTypes = DB::table('user_types')->pluck('name', 'id'); // Fetch user types from the database
        return Inertia::render('Auth/Register')->with('userTypes', $userTypes); // Pass user types to the view
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'user_type' => 'required|exists:user_types,id', // Validate user_type
        ]);
        // dd($request->all());

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'user_type_id' => $request->user_type, // Set the user_type attribute
        ]);

        event(new Registered($user));

        Auth::login($user);

        if(auth('web')->user()->userType->id === 2) {
            return redirect(route('vendor.dashboard', absolute: false)); // Redirect to vendor dashboard if user type is vendor
        }
        return redirect(route('dashboard', absolute: false));
    }
}
