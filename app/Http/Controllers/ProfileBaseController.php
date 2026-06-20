<?php

namespace App\Http\Controllers;

use App\Actions\UpdateProfileImage;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

abstract class ProfileBaseController extends Controller
{
    abstract public function edit(Request $request): Response;
    abstract public function destroy(Request $request): RedirectResponse;

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->except('image'));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($request->hasFile('image')) {
            $user->image = UpdateProfileImage::run($user, $request->file('image'));
        }

        // Despacha o Job para o Horizon otimizar em background
        // if ($request->hasFile('image') && $user->image) {
        //     ProcessImageOptimization::dispatch($user, 'image', 'profile_images');
        // }

        $user->save();

        return back()->with('success', 'Profile updated successfully.');
    }
}