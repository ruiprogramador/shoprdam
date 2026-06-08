<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYC Submissions — {{ $printed_at }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 12px;
            color: #111;
            background: white;
            padding: 24px;
        }

        /* ── Header ── */
        .print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }
        .print-header h1 { font-size: 18px; font-weight: 700; color: #111; }
        .print-header p  { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .print-meta      { text-align: right; font-size: 11px; color: #6b7280; }

        /* ── Table ── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        thead tr {
            background: #f3f4f6;
        }

        th {
            padding: 8px 10px;
            text-align: left;
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }

        td {
            padding: 8px 10px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            color: #374151;
        }

        tr:last-child td { border-bottom: none; }

        tr:nth-child(even) td { background: #f9fafb; }

        /* ── Status badge ── */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge--pending  { background: #fef9c3; color: #854d0e; }
        .badge--approved { background: #dcfce7; color: #166534; }
        .badge--rejected { background: #fee2e2; color: #991b1b; }
        .badge--default  { background: #f3f4f6; color: #374151; }

        /* ── Footer ── */
        .print-footer {
            margin-top: 16px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #9ca3af;
            display: flex;
            justify-content: space-between;
        }

        /* ── Print media ── */
        @media print {
            body { padding: 12px; }
            @page { margin: 1cm; size: A4 landscape; }
        }
    </style>
</head>
<body>

    <div class="print-header">
        <div>
            <h1>KYC Submissions</h1>
            <p>Vendor verification records</p>
        </div>
        <div class="print-meta">
            <div>Printed: {{ $printed_at }}</div>
            <div>Total records: {{ count($kycs) }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Vendor</th>
                <th>Email</th>
                <th>Full Name</th>
                <th>Country</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Expires</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($kycs as $i => $kyc)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $kyc['user']['name'] ?? '—' }}</td>
                    <td>{{ $kyc['user']['email'] ?? '—' }}</td>
                    <td>{{ $kyc['full_name'] ?? '—' }}</td>
                    <td>{{ $kyc['country']['name'] ?? '—' }}</td>
                    <td>
                        @php $slug = $kyc['kyc_status']['slug'] ?? 'default'; @endphp
                        <span class="badge badge--{{ in_array($slug, ['pending','approved','rejected']) ? $slug : 'default' }}">
                            {{ $kyc['kyc_status']['name'] ?? '—' }}
                        </span>
                    </td>
                    <td>{{ isset($kyc['created_at']) ? \Carbon\Carbon::parse($kyc['created_at'])->format('d/m/Y H:i') : '—' }}</td>
                    <td>{{ isset($kyc['expires_at']) ? \Carbon\Carbon::parse($kyc['expires_at'])->format('d/m/Y') : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align:center; padding: 24px; color: #9ca3af;">
                        No records found for the selected filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="print-footer">
        <span>KYC Management System</span>
        <span>{{ $printed_at }}</span>
    </div>

    <script>
        // Auto-trigger print dialog quando a página carrega
        window.addEventListener('load', () => window.print())
    </script>

</body>
</html>