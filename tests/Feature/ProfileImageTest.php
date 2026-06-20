<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

it('uploads a profile image and optimizes it synchronously', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('avatar.jpg', 800, 800);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name'  => $user->name,
            'email' => $user->email,
            'image' => $file,
        ])
        ->assertRedirect();

    $user->refresh();
    expect($user->image)->not->toBeNull()
        ->and($user->image)->toContain('.webp');

    $files = Storage::disk('public')->allFiles();
    expect($files)->toContain($user->image);
});

it('does not update image when no image is uploaded', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name'  => $user->name,
            'email' => $user->email,
        ])
        ->assertRedirect();

    $user->refresh();
    expect($user->image)->toBeNull();
});

it('replaces the old image when a new one is uploaded', function () {
    Storage::disk('public')->put('profile_images/old.webp', 'fake');

    $user = User::factory()->create(['image' => 'profile_images/old.webp']);
    $file = UploadedFile::fake()->image('new.jpg', 800, 800);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name'  => $user->name,
            'email' => $user->email,
            'image' => $file,
        ]);

    $user->refresh();
    expect($user->image)->toContain('.webp')
        ->and($user->image)->not->toBe('profile_images/old.webp');

    $files = Storage::disk('public')->allFiles();
    expect($files)->not->toContain('profile_images/old.webp');
});