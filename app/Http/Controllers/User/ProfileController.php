<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\ProfileBaseController;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;

use Inertia\Inertia;
use Inertia\Response;

use App\Traits\FileUploadTrait;

class ProfileController extends ProfileBaseController
{

    use FileUploadTrait;

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        if ($request->user()->userType->id === 2) {
            return Inertia::render('Vendor/Profile/Edit', [
                'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
                'status' => session('status'),
                'imageUrl' => $request->user()->image ? Storage::disk('public')->url($request->user()->image) : null,
                'updateProfileUrl' => 'profile.update',
                'updatePasswordUrl' => 'password.update',
                'deleteUserUrl' => 'profile.destroy',
            ]);
        }
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'imageUrl' => $request->user()->image ? Storage::disk('public')->url($request->user()->image) : null
        ]);
    }

    /**
     * Delete the user's account.
     *
     * A vendor (any user owning at least one Store, including a
     * soft-deleted one, regardless of whether it has accumulated any
     * financial history yet) can never self-service hard-delete their
     * account: stores.user_id is restrictOnDelete() at the database level
     * (see docs/financial/INVARIANTS.md CROSS-14), and this check exists so
     * that constraint is never what the user "discovers" via a 500 — the
     * decision is made here, before any mutation, not by catching a
     * QueryException.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if ($user->stores()->withTrashed()->exists()) {
            return Redirect::back()->withErrors([
                'account' => __('Your account cannot be deleted while it owns a store. Please contact support.'),
            ]);
        }

        Auth::guard('web')->logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
