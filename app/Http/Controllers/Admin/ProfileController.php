<?php

namespace App\Http\Controllers\Admin;

use App\Actions\OptimizeImage;
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
use App\Traits\FileUploadTrait;

class ProfileController extends Controller
{

    use FileUploadTrait;

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Admin/Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'imageUrl' => $request->user()->image ? Storage::disk('public')->url($request->user()->image) : null,
            'updateProfileUrl' => 'admin.profile.update',
            'updatePasswordUrl' => 'admin.password.update',
            'deleteUserUrl' => 'admin.profile.destroy',
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

        // Add or Replace
        $oldImagePath = $user->getOriginal('image');

        if ($request->hasFile('image')) {
            // 1. Guarda o ficheiro bruto (temporário)
            $rawPath = $this->handleFileUpload(
                $request->file('image'),
                null,
                'profile_images',
                'public'
            );

            // 2. Apaga o antigo manualmente (agora que o trait não o faz)
            if ($oldImagePath) {
                Storage::disk('public')->delete($oldImagePath);
            }

            // 3. Atualiza com o path bruto (será substituído pelo Job)
            $user->image = OptimizeImage::run($rawPath, 'profile_images');
        }

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

        Auth::guard('admin')->logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
