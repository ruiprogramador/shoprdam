<?php

use App\Events\KycApproved;
use App\Events\KycExpired;
use App\Events\KycExpiringSoon;
use App\Events\KycRejected;
use App\Events\KycSubmitted;
use App\Events\KycUpdated;
use App\Listeners\NotifyAdminKycSubmitted;
use App\Listeners\NotifyAdminKycUpdated;
use App\Listeners\NotifyVendorKycApproved;
use App\Listeners\NotifyVendorKycExpired;
use App\Listeners\NotifyVendorKycExpiringSoon;
use App\Listeners\NotifyVendorKycRejected;
use App\Models\Admin;
use App\Models\Kyc;
use App\Models\User;
use App\Notifications\KycApprovedNotification;
use App\Notifications\KycExpiredNotification;
use App\Notifications\KycExpiringSoonNotification;
use App\Notifications\KycRejectedNotification;
use App\Notifications\KycSubmittedNotification;
use App\Notifications\KycUpdatedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    /* $this->afterRefreshingDatabase(function () {
        $this->seed(KycStatusSeeder::class);
        $this->seed(GenderSeeder::class);
        $this->seed(TestWorldSeeder::class);

        dump('kyc_status: ' . \Illuminate\Support\Facades\DB::table('kyc_status')->count());
        dump('genders: ' . \Illuminate\Support\Facades\DB::table('genders')->count());
        dump('countries: ' . \Illuminate\Support\Facades\DB::table('countries')->count());
    });
    
    // Debug
    // \Illuminate\Support\Facades\DB::table('kyc_status')->count() > 0 
    //     ? dump('kyc_status populated') 
    //     : dump('kyc_status EMPTY'); */

    Event::fake();
    Notification::fake();
});

// KycSubmitted
it('dispatches KycSubmitted event when vendor submits KYC', function () {
    $kyc = Kyc::factory()->create();
    KycSubmitted::dispatch($kyc);
    Event::assertDispatched(KycSubmitted::class);
});

it('notifies admins when KycSubmitted is dispatched', function () {
    // dump('connection: ' . DB::connection()->getName());
    // dump('kyc_status in test: ' . DB::table('kyc_status')->count());
    // dump('kyc_status ids: ' . DB::table('kyc_status')->pluck('id')->implode(','));
    
    $user = User::factory()->create();
    $admin = Admin::factory()->create();
    $kyc = Kyc::factory()->create(['user_id' => $user->id]);

    $listener = new NotifyAdminKycSubmitted();
    $listener->handle(new KycSubmitted($kyc));

    Notification::assertSentTo($admin, KycSubmittedNotification::class);
});

// KycApproved
it('dispatches KycApproved event when KYC is approved', function () {
    $kyc = Kyc::factory()->approved()->create();
    KycApproved::dispatch($kyc);
    Event::assertDispatched(KycApproved::class);
});

it('notifies vendor when KycApproved is dispatched', function () {
    $user = User::factory()->create();
    $kyc = Kyc::factory()->approved()->create(['user_id' => $user->id]);

    $listener = new NotifyVendorKycApproved();
    $listener->handle(new KycApproved($kyc));

    Notification::assertSentTo($user, KycApprovedNotification::class);
});

// KycRejected
it('dispatches KycRejected event when KYC is rejected', function () {
    $kyc = Kyc::factory()->rejected()->create();
    KycRejected::dispatch($kyc);
    Event::assertDispatched(KycRejected::class);
});

it('notifies vendor when KycRejected is dispatched', function () {
    $user = User::factory()->create();
    $kyc = Kyc::factory()->rejected()->create(['user_id' => $user->id]);

    $listener = new NotifyVendorKycRejected();
    $listener->handle(new KycRejected($kyc));

    Notification::assertSentTo($user, KycRejectedNotification::class);
});

// KycUpdated
it('dispatches KycUpdated event when vendor updates KYC', function () {
    $kyc = Kyc::factory()->create();
    KycUpdated::dispatch($kyc);
    Event::assertDispatched(KycUpdated::class);
});

it('notifies admins when KycUpdated is dispatched', function () {
    $user = User::factory()->create();
    $admin = Admin::factory()->create();
    $kyc = Kyc::factory()->create(['user_id' => $user->id]);

    $listener = new NotifyAdminKycUpdated();
    $listener->handle(new KycUpdated($kyc));

    Notification::assertSentTo($admin, KycUpdatedNotification::class);
});

// KycExpiringSoon
it('dispatches KycExpiringSoon event when KYC is expiring soon', function () {
    $kyc = Kyc::factory()->expiringSoon()->create();
    KycExpiringSoon::dispatch($kyc);
    Event::assertDispatched(KycExpiringSoon::class);
});

it('notifies vendor when KycExpiringSoon is dispatched', function () {
    $user = User::factory()->create();
    $kyc = Kyc::factory()->expiringSoon()->create(['user_id' => $user->id]);

    $listener = new NotifyVendorKycExpiringSoon();
    $listener->handle(new KycExpiringSoon($kyc));

    Notification::assertSentTo($user, KycExpiringSoonNotification::class);
});

// KycExpired
it('dispatches KycExpired event when KYC expires', function () {
    $kyc = Kyc::factory()->expired()->create();
    KycExpired::dispatch($kyc);
    Event::assertDispatched(KycExpired::class);
});

it('notifies vendor when KycExpired is dispatched', function () {
    $user = User::factory()->create();
    $kyc = Kyc::factory()->expired()->create(['user_id' => $user->id]);

    $listener = new NotifyVendorKycExpired();
    $listener->handle(new KycExpired($kyc));

    Notification::assertSentTo($user, KycExpiredNotification::class);
});