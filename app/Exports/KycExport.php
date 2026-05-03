<?php

namespace App\Exports;

use App\Models\Kyc;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithProperties;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class KycExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithTitle, WithProperties, ShouldAutoSize
{
    public function __construct(
        private readonly array $filters = [],
    ) {}

    public function title(): string
    {
        return 'KYC Export';
    }

    public function properties(): array
    {
        return [
            'creator'     => config('app.name'),
            'title'       => 'KYC Export',
            'description' => 'KYC records exported on ' . now()->format('d/m/Y H:i'),
        ];
    }

    public function query()
    {
        return Kyc::query()
            ->with(['kycStatus', 'user', 'country', 'state', 'city', 'gender'])
            ->when($this->filters['status'] ?? null, fn ($q, $v) =>
                $q->whereHas('kycStatus', fn ($q2) => $q2->where('slug', $v))
            )
            ->when($this->filters['search'] ?? null, fn ($q, $v) =>
                $q->where(fn ($q2) =>
                    $q2->where('full_name', 'like', "%{$v}%")
                       ->orWhereHas('user', fn ($q3) =>
                           $q3->where('name', 'like', "%{$v}%")
                              ->orWhere('email', 'like', "%{$v}%")
                       )
                )
            )
            ->when($this->filters['country_id'] ?? null, fn ($q, $v) =>
                $q->where('country_id', $v)
            )
            ->when($this->filters['date_from'] ?? null, fn ($q, $v) =>
                $q->whereDate('created_at', '>=', $v)
            )
            ->when($this->filters['date_to'] ?? null, fn ($q, $v) =>
                $q->whereDate('created_at', '<=', $v)
            )
            ->latest();
    }

    public function headings(): array
    {
        return [
            '#',
            'Vendor Name',
            'Vendor Email',
            'Full Name',
            'Date of Birth',
            'Gender',
            'Country',
            'State / Province',
            'City',
            'Postal Code',
            'Address',
            'KYC Status',
            'Submitted At',
            'Reviewed At',
            'Expires At',
        ];
    }

    public function map($kyc): array
    {
        return [
            $kyc->id,
            $kyc->user?->name,
            $kyc->user?->email,
            $kyc->full_name,
            $kyc->date_of_birth?->format('d/m/Y'),
            $kyc->gender?->name,
            $kyc->country?->name,
            $kyc->state?->name,
            $kyc->city?->name,
            $kyc->postal_code,
            trim(implode(', ', array_filter([$kyc->address_line1, $kyc->address_line2]))),
            $kyc->kycStatus?->name,
            $kyc->created_at?->format('d/m/Y H:i'),
            $kyc->reviewed_at?->format('d/m/Y H:i'),
            $kyc->expires_at?->format('d/m/Y'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Header row — bold + background
            1 => [
                'font' => [
                    'bold'  => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'], // indigo
                ],
            ],
        ];
    }
}