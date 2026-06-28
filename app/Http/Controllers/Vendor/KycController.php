<?php

namespace App\Http\Controllers\Vendor;

use App\Actions\StoreKycAction;
use App\Actions\UpdateKycAction;
use App\Events\KycSubmitted;
use App\Events\KycUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StoreKycRequest;
use App\Http\Requests\Vendor\UpdateKycRequest;
use App\Http\Resources\KycResource;
use App\Models\City;
use App\Models\Country;
use App\Models\DocumentSide;
use App\Models\DocumentType;
use App\Models\Kyc;
use App\Models\Gender;
use App\Models\State;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class KycController extends Controller
{
    public function __construct(
        private readonly StoreKycAction $storeKyc,
        private readonly UpdateKycAction $updateKyc,
    ) {}

    /**
     * Display the vendor's KYC status and history.
     */
    public function show(): Response
    {
        $kyc = auth()->user()->kyc?->load([
            'kycStatus',
            'country',
            'state',
            'city',
            'gender',
            'documents.documentType',
            'documents.documentSide',
            'history.fromStatus',
            'history.toStatus',
            'history.reviewer',
        ]);

        return Inertia::render('Vendor/Kyc/Show', [
            'kyc' => $kyc ? KycResource::make($kyc)->resolve() : null,
        ]);
    }

    /**
     * Show the form to submit a new KYC.
     */
    public function create(): Response|RedirectResponse
    {
        if (!auth()->user()->canSubmitKyc()) {
            return redirect()->route('vendor.kyc.show')
                ->with('error', __('kyc.cannot_submit'));
        }

        return Inertia::render('Vendor/Kyc/Form', [
            'countries'      => Country::active()->get(['id', 'name', 'iso2']),
            'genders'        => Gender::active()->get(['id', 'name', 'slug']),
            'document_types' => DocumentType::active()->get(['id', 'name']),
            'document_sides' => DocumentSide::active()->get(['id', 'name']),
        ]);
    }

    /**
     * Store a newly submitted KYC.
     */
    public function store(StoreKycRequest $request): RedirectResponse
    {
        $this->authorize('create', Kyc::class);

        $kyc = $this->storeKyc->execute(auth()->user(), $request->validated());

        KycSubmitted::dispatch($kyc);

        return redirect()->route('vendor.kyc.show')
            ->with('success', __('kyc.submitted'));
    }

    /**
     * Show the form to edit an existing KYC.
     */
    public function edit(): Response|RedirectResponse
    {
        $kyc = auth()->user()->kyc;

        if (!$kyc || !$kyc->canBeEdited()) {
            return redirect()->route('vendor.kyc.show')
                ->with('error', __('kyc.cannot_edit'));
        }

        $kyc->load([
            'kycStatus',
            'country',
            'state',
            'city',
            'gender',
            'documents.documentType',
            'documents.documentSide',
        ]);

        return Inertia::render('Vendor/Kyc/Form', [
            'kyc'            => KycResource::make($kyc),
            'countries'      => Country::active()->get(['id', 'name', 'iso2']),
            'genders'        => Gender::active()->get(['id', 'name', 'slug']),
            'document_types' => DocumentType::active()->get(['id', 'name']),
            'document_sides' => DocumentSide::active()->get(['id', 'name']),
            'states'         => $kyc->country_id
                ? State::forCountry($kyc->country_id)->get(['id', 'name'])
                : [],
            'cities'         => $kyc->state_id
                ? City::forState($kyc->state_id)->get(['id', 'name'])
                : [],
        ]);
    }

    /**
     * Update the vendor's existing KYC.
     */
    public function update(UpdateKycRequest $request): RedirectResponse
    {
        $kyc = auth()->user()->kyc;

        $this->authorize('update', $kyc);

        $kyc = $this->updateKyc->execute($kyc, $request->validated());

        KycUpdated::dispatch($kyc); // Notification is sent to the admin when a KYC is updated, only when it's submitted for the first time.

        return redirect()->route('vendor.kyc.show')
            ->with('success', __('kyc.updated'));
    }
}