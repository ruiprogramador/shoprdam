<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ReviewKycAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\KycResource;
use App\Models\Kyc;
use App\Models\KycStatus;
use Inertia\Response;
use Inertia\Inertia;
use App\Http\Requests\Admin\ReviewKycRequest;
use App\Policies\AdminKycPolicy;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use App\Exports\KycExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KycController extends Controller
{
    public function __construct(private readonly ReviewKycAction $reviewKyc){}

    /**
     * List all KYC 
     */
    public function index(): Response
    {
        $kycs = QueryBuilder::for(Kyc::class)
        ->with(['kycStatus', 'user', 'country'])
        ->allowedFilters(
            AllowedFilter::callback('search', function ($query, $value) {
                $query->where(function ($q) use ($value) {
                    $q->where('full_name', 'like', "%{$value}%")
                    ->orWhereHas('user', fn ($q) =>
                            $q->where('name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%")
                    );
                });
            }),
            AllowedFilter::callback('status', fn ($query, $value) =>
                $query->whereHas('kycStatus', fn ($q) => $q->where('slug', $value))
            ),
            AllowedFilter::exact('country_id'),
            AllowedFilter::callback('date_from', fn ($q, $v) =>
                $q->whereDate('created_at', '>=', $v)
            ),
            AllowedFilter::callback('date_to', fn ($q, $v) =>
                $q->whereDate('created_at', '<=', $v)
            ),
            AllowedFilter::callback('expiring_soon', fn ($q, $v) =>
                $v ? $q->expiringSoon() : $q
            ),
        )
        ->allowedSorts(
            AllowedSort::field('full_name'),
            AllowedSort::field('created_at'),
            AllowedSort::field('reviewed_at'),
            AllowedSort::field('expires_at'),
        )
        ->defaultSort('-created_at')
        ->paginate(request('per_page', 10))
        ->withQueryString();
 
        $stats = [
            'total'         => Kyc::count(),
            'pending'       => Kyc::pending()->count(),
            'approved'      => Kyc::approved()->count(),
            'rejected'      => Kyc::rejected()->count(),
            'expiring_soon' => Kyc::expiringSoon()->count(),
        ];
 
        return Inertia::render('Admin/Kyc/Index', [
            'kycs'      => KycResource::collection($kycs)->resolve(),
            'stats'     => $stats,
            'statuses'  => KycStatus::active()->get(['id', 'name', 'slug']),
            'countries' => Country::active()->get(['id', 'name']),
            'filters'   => request()->only(['filter', 'sort', 'per_page']),
        ]);
    }

    /**
     * Show a specific KYC
     */
    public function show(Kyc $kyc): Response
    {
        $this->authorizeAdmin('view', $kyc);

        $kyc->load([
            'kycStatus'
            , 'user'
            , 'reviewer'
            , 'country'
            , 'state'
            , 'city'
            , 'gender'
            , 'documents.documentType'
            , 'documents.documentSide'
            , 'history.fromStatus'
            , 'history.toStatus'
            , 'history.reviewer'
        ]);

        return Inertia::render('Admin/Kyc/Show', array(
            'kyc' => $kyc ? KycResource::make($kyc)->resolve() : null,
        ));
    }

    /**
     * Review a KYC (approve/reject)
     */
    public function review(ReviewKycRequest $request, Kyc $kyc): RedirectResponse
    {
        $this->authorizeAdmin('review', $kyc);

        $action = $request->validated('action');

        $kyc = $this->reviewKyc->execute(
            kyc: $kyc,
            reviewer: auth()->guard('admin')->user(),
            action: $action,
            notes: $request->validated('notes'),
        );

        return redirect()->route('admin.kyc.show', $kyc)->with('success', 'KYC reviewed successfully.');
    }

    public function export(): BinaryFileResponse
    {
        $filters = request()->only(['status', 'search', 'country_id', 'date_from', 'date_to']);
    
        $filename = 'kyc-export-' . now()->format('Y-m-d-His') . '.xlsx';
    
        return Excel::download(new KycExport($filters), $filename);
    }

    /* private function authorizeAdmin(string $ability, Kyc $kyc): void
    {
        $admin = auth('admin')->user(); 
        abort_unless($admin && (new AdminKycPolicy())->{$ability}($admin, $kyc), 403);
    } */

    private function authorizeAdmin(string $ability, Kyc $kyc): void
    {
        $admin = auth('admin')->user();
        $policy = app(AdminKycPolicy::class);

        abort_unless(
            $admin && method_exists($policy, $ability) && $policy->{$ability}($admin, $kyc),
            403
        );
    }
}
