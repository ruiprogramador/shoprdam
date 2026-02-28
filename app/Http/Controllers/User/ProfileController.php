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
use App\Traits\FileUploadTrait;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class ProfileController extends Controller
{

    use FileUploadTrait;

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'imageUrl' => $request->user()->image ? Storage::disk('public')->url($request->user()->image) : null,
        ]);
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

        $this->handleProfileImage($request, $user);

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

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function handleProfileImage(ProfileUpdateRequest $request, User $user): void
    {
        $oldImage = $user->getOriginal('image');

        if ($request->hasFile('image')) {
            // dd('New image uploaded:', $request->file('image')); // Debug the uploaded file
            $user->image = $this->uploadFile(
                $request->file('image'),
                'profile_images',
                'public'
            );
            if ($oldImage) {
                Storage::disk('public')->delete($oldImage);
            }
        } elseif ($request->input('image') === null && $oldImage) {
            Storage::disk('public')->delete($oldImage);
            $user->image = null;
        } elseif ($request->input('image') === $oldImage) {
        } else {
            if ($oldImage) {
                Storage::disk('public')->delete($oldImage);
            }
            $user->image = null;
        }
    }
}
