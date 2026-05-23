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
    public function __construct(private readonly ReviewKycAction $reviewKyc) {}

    /**
     * List all KYC submissions.
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

        // ── Stats cards (backend decide quais e quantos mostrar) ─────────────
        $statsCards = [
            [
                'label'       => 'Total',
                'description' => 'All KYC submissions',
                'value'       => Kyc::count(),
                'icon'        => '📊',
                'filter_slug' => null,
                'color'       => 'blue',
            ],
            [
                'label'       => 'Pending',
                'description' => 'Awaiting review',
                'value'       => Kyc::pending()->count(),
                'icon'        => '⏳',
                'filter_slug' => 'pending',
                'color'       => 'yellow',
            ],
            [
                'label'       => 'Approved',
                'description' => 'Verified vendors',
                'value'       => Kyc::approved()->count(),
                'icon'        => '✅',
                'filter_slug' => 'approved',
                'color'       => 'green',
            ],
            [
                'label'       => 'Rejected',
                'description' => 'Failed verification',
                'value'       => Kyc::rejected()->count(),
                'icon'        => '❌',
                'filter_slug' => 'rejected',
                'color'       => 'red',
            ],
            [
                'label'       => 'Expiring',
                'description' => 'Needs renewal soon',
                'value'       => Kyc::expiringSoon()->count(),
                'icon'        => '⚠️',
                'filter_slug' => 'expiring_soon',
                'color'       => 'orange',
            ],
        ];

        // ── Filter fields (backend decide quais campos o painel mostra) ───────
        // SVG path (d=) para os ícones; omitir 'icon' para sem ícone.
        $statuses  = KycStatus::active()->get(['id', 'name', 'slug']);
        $countries = Country::active()->get(['id', 'name']);

        $filterFields = [
            [
                'type'        => 'search',
                'key'         => 'search',
                'label'       => 'Search',
                'placeholder' => 'Name, email…',
                'icon'        => 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z',
            ],
            [
                'type'    => 'select',
                'key'     => 'status',
                'label'   => 'Status',
                'icon'    => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
                'options' => array_merge(
                    [['value' => '', 'label' => 'All Statuses']],
                    $statuses->map(fn ($s) => ['value' => $s->slug, 'label' => $s->name])->all()
                ),
            ],
            [
                'type'    => 'select',
                'key'     => 'country_id',
                'label'   => 'Country',
                'icon'    => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18',
                'options' => array_merge(
                    [['value' => '', 'label' => 'All Countries']],
                    $countries->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all()
                ),
            ],
            [
                'type'     => 'date_range',
                'key'      => 'date_from',
                'key_to'   => 'date_to',
                'label'    => 'From',
                'label_to' => 'To',
                'icon'     => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5',
            ],
        ];

        // Export actions (para mostrar opções de exportação no painel, como Excel, CSV, etc.)
        $exportActions = [
            [
                'label'  => 'Excel',
                'icon'   => '📊',
                'format' => 'xlsx',
                'route'  => 'admin.kyc.export',
            ],
            [
                'label'  => 'CSV',
                'icon'   => '📄',
                'format' => 'csv',
                'route'  => 'admin.kyc.export',
            ],
            [
                'label'  => 'Print',
                'icon'   => '🖨️',
                'format' => 'print',
                'route'  => 'admin.kyc.print',
            ],
        ];

        return Inertia::render('Admin/Kyc/Index', [
            'kycs'          => KycResource::collection($kycs)->resolve(),
            'stats_cards'   => $statsCards,
            'filter_fields' => $filterFields,
            'filters'       => request()->only(['filter', 'sort', 'per_page']),
            'export_actions' => $exportActions,
        ]);
    }

    /**
     * Show a specific KYC.
     */
    public function show(Kyc $kyc): Response
    {
        $this->authorizeAdmin('view', $kyc);

        $kyc->load([
            'kycStatus',
            'user',
            'reviewer',
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

        return Inertia::render('Admin/Kyc/Show', [
            'kyc' => $kyc ? KycResource::make($kyc)->resolve() : null,
        ]);
    }

    /**
     * Review a KYC (approve/reject).
     */
    public function review(ReviewKycRequest $request, Kyc $kyc): RedirectResponse
    {
        $this->authorizeAdmin('review', $kyc);

        $kyc = $this->reviewKyc->execute(
            kyc: $kyc,
            reviewer: auth()->guard('admin')->user(),
            action: $request->validated('action'),
            notes: $request->validated('notes'),
        );

        return redirect()->route('admin.kyc.show', $kyc)->with('success', 'KYC reviewed successfully.');
    }

    public function export(): BinaryFileResponse
    {
        // $filters  = request()->only(['status', 'search', 'country_id', 'date_from', 'date_to']);
        $filters = request()->input('filter', []);
        $format   = request()->input('format', 'xlsx');
        $filename = 'kyc-export-' . now()->format('Y-m-d-His') . "." . $format;

        $writerType = match ($format) {
            'csv'   => \Maatwebsite\Excel\Excel::CSV,
            default => \Maatwebsite\Excel\Excel::XLSX,
        };
 
        return Excel::download(new KycExport($filters), $filename, $writerType);
    }

    public function print(): \Illuminate\Http\Response
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
            )
            ->defaultSort('-created_at')
            ->get();
 
        return response()->view('admin.kyc.print', [
            'kycs'       => KycResource::collection($kycs)->resolve(),
            'printed_at' => now()->format('d/m/Y H:i'),
        ]);
    }

    private function authorizeAdmin(string $ability, Kyc $kyc): void
    {
        $admin  = auth('admin')->user();
        $policy = app(AdminKycPolicy::class);

        abort_unless(
            $admin && method_exists($policy, $ability) && $policy->{$ability}($admin, $kyc),
            403
        );
    }
}