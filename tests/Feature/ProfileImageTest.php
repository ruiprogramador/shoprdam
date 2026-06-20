<?php

use App\Jobs\ProcessImageOptimization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

it('uploads a profile image and dispatches the optimization job', function () {
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

    expect($user->image)->not->toBeNull();

    $files = Storage::disk('public')->allFiles();
    expect($files)->toContain($user->image);

    Queue::assertPushed(ProcessImageOptimization::class, function ($job) use ($user) {
        return $job->model->is($user)
            && $job->attribute === 'image'
            && $job->folder   === 'profile_images';
    });
});

it('does not dispatch job when no image is uploaded', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name'  => $user->name,
            'email' => $user->email,
        ])
        ->assertRedirect();

    Queue::assertNotPushed(ProcessImageOptimization::class);
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

    Storage::assertMissing('profile_images/old.webp');
});