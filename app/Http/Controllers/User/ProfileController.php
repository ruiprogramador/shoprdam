<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Traits\FileUploadTrait;

class ProfileController extends Controller
{

    use FileUploadTrait;

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        /* return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'imageUrl' => $request->user()->image ? Storage::disk('public')->url($request->user()->image) : null,
        ]); */
        if ($request->user()->userType->id === 2) {
            return Inertia::render('Vendor/Profile/Edit', [
                'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
                'status' => session('status'),
                'imageUrl' => $request->user()->image ? Storage::disk('public')->url($request->user()->image) : null,
                'updateProfileUrl' => 'profile.update',
                'updatePasswordUrl' => 'password.update',
                'deleteUserUrl' => 'profile.destroy',
            ]);
        } else {
            return Inertia::render('Profile/Edit', [
                'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
                'status' => session('status'),
                'imageUrl' => $request->user()->image ? Storage::disk('public')->url($request->user()->image) : null
            ]);
        }
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        // $user->fill($request->validated());
        $user->fill($request->safe()->except('image'));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        // Add or Replace
        $user->image = $this->handleFileUpload(
            $request->file('image'),
            $user->getOriginal('image'),
            'profile_images',
            'public'
        );

        $user->save();

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::guard('web')->logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
