@extends('layouts.app')

@section('content')
    <div class="pnl-excel-container">
        <!-- Header Section -->
        <div class="excel-header mb-4">
            <div
                class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="country-flag">
                            @if ($country == 'LK')
                                <i class="fas fa-flag-checkered"></i>
                            @elseif($country == 'SG')
                                <i class="fas fa-city"></i>
                            @elseif($country == 'MY')
                                <i class="fas fa-tree"></i>
                            @elseif($country == 'VN')
                                <i class="fas fa-umbrella-beach"></i>
                            @else
                                <i class="fas fa-globe"></i>
                            @endif
                        </div>
                        <div>
                            <h1 class="excel-title">
                                Profit & Loss Statement
                            </h1>
                            <p class="excel-subtitle">
                                {{ $countryName }} ({{ $country }}) - Detailed Breakdown
                            </p>
                        </div>
                    </div>
                </div>
              <div class="d-flex gap-2">
    <a href="{{ route('pnl.index') }}" class="btn-excel btn-excel-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
    <a href="{{ route('pnl.export-country', $country) }}" class="btn-excel btn-excel-success">
        <i class="fas fa-download me-1"></i> Export All (CSV)
    </a>
    <a href="{{ route('pnl.export-country-approved', $country) }}" class="btn-excel btn-excel-warning">
        <i class="fas fa-check-circle me-1"></i> Export Updated Only
    </a>
</div>
            </div>
        </div>

        <!-- Stats Summary Cards -->
        {{-- <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="summary-content">
                    <span class="summary-label">Total Amount</span>
                    <h3 class="summary-value">
                        {{ $country == 'SG' ? 'SGD' : ($country == 'MY' ? 'MYR' : ($country == 'VN' ? 'VND' : 'LKR')) }}
                        {{ number_format($totalAmount ?? 0, 2) }}
                    </h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="summary-content">
                    <span class="summary-label">Exchange Rate</span>
                    <h3 class="summary-value">
                        1 USD = {{ number_format($exchangeRate ?? 1, 2) }}
                    </h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="summary-content">
                    <span class="summary-label">Last Updated</span>
                    <h3 class="summary-value">
                        {{ now()->format('d M Y') }}
                    </h3>
                </div>
            </div>
        </div>
    </div> --}}

        <!-- Excel Table Card -->
        <div class="excel-card">
            <div class="excel-card-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-table"></i>
                    <span>Data Sheet - {{ $countryName }}</span>
                </div>
                {{-- <div>
                <span class="record-count">
                    <i class="fas fa-database me-1"></i> 
                    {{ $recordCount ?? 0 }} Records
                </span>
            </div> --}}
            </div>
            <div class="excel-card-body">
                <div class="table-responsive">
                    {!! $html !!}
                </div>
            </div>
        </div>

        <!-- Footer Note -->
        {{-- <div class="excel-footer mt-4">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-info-circle"></i>
            <span>
                <strong>Note:</strong> Each attraction, transfer, and hotel gets its own row. 
                Amounts are converted from USD to local currency using the current exchange rate.
            </span>
        </div>
    </div> --}}
    </div>

    <style>
        /* PnL Excel Page Styles */
        .pnl-excel-container {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Header Styles */
        .excel-header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            border-radius: 20px;
            padding: 1.75rem 2rem;
            color: white;
        }

        .country-flag {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }

        .excel-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 0.25rem 0;
        }

        .excel-subtitle {
            font-size: 0.85rem;
            opacity: 0.9;
            margin: 0;
        }

        /* Button Styles */
        .btn-excel {
            display: inline-flex;
            align-items: center;
            padding: 0.6rem 1.25rem;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .btn-excel-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-excel-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateY(-1px);
        }

        .btn-excel-success {
            background-color: white;
            color: #059669;
        }

        .btn-excel-success:hover {
            background-color: #f8fafc;
            transform: translateY(-1px);
            color: #047857;
        }

        /* Summary Cards */
        .summary-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .summary-icon {
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #059669;
        }

        .summary-content {
            flex: 1;
        }

        .summary-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            display: block;
            margin-bottom: 0.25rem;
        }

        .summary-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        /* Excel Card */
        .excel-card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .excel-card-header {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .excel-card-header .fa-table {
            color: #059669;
        }

        .record-count {
            font-size: 0.75rem;
            font-weight: 500;
            color: #64748b;
            background: #f1f5f9;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
        }

        .excel-card-body {
            padding: 0;
            overflow-x: auto;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .excel-table thead th {
            background: #f8fafc;
            padding: 1rem;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .excel-table tbody td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: top;
        }

        .excel-table tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Specific column styles */
        .excel-table td:first-child,
        .excel-table th:first-child {
            position: sticky;
            left: 0;
            background: white;
            z-index: 10;
        }

        .excel-table tbody tr:hover td:first-child {
            background: #f8fafc;
        }

        /* Numeric columns */
        .excel-table td.amount-cell {
            font-weight: 600;
            color: #059669;
            text-align: right;
        }

        .excel-table th.text-end {
            text-align: right;
        }

        /* Footer */
        .excel-footer {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            border: 1px solid #e2e8f0;
            font-size: 0.75rem;
            color: #475569;
        }

        .excel-footer i {
            color: #059669;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .excel-header {
                padding: 1.25rem;
            }

            .country-flag {
                width: 45px;
                height: 45px;
                font-size: 1.25rem;
            }

            .excel-title {
                font-size: 1.1rem;
            }

            .excel-subtitle {
                font-size: 0.7rem;
            }

            .summary-card {
                padding: 1rem;
            }

            .summary-icon {
                width: 45px;
                height: 45px;
                font-size: 1.25rem;
            }

            .summary-value {
                font-size: 1rem;
            }

            .summary-label {
                font-size: 0.6rem;
            }

            .excel-card-header {
                padding: 0.875rem 1rem;
            }

            .excel-table thead th,
            .excel-table tbody td {
                padding: 0.625rem 0.75rem;
                font-size: 0.7rem;
            }
        }

        /* Print styles */
        @media print {

            .btn-excel,
            .excel-footer,
            .summary-card:not(:first-child) {
                display: none;
            }

            .excel-header {
                background: #059669;
                print-color-adjust: exact;
            }

            .excel-card {
                border: 1px solid #ddd;
            }

            .excel-table thead th {
                background: #f0f0f0;
                print-color-adjust: exact;
            }
        }

        /* Custom scrollbar */
        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Amount highlighting */
        .amount-positive {
            color: #059669;
            font-weight: 600;
        }

        .amount-negative {
            color: #dc2626;
            font-weight: 600;
        }
        .btn-excel-warning {
    background-color: #f59e0b;
    color: white;
}

.btn-excel-warning:hover {
    background-color: #d97706;
    transform: translateY(-1px);
    color: white;
}
    </style>

    @push('scripts')
        <script>
            // Add classes to the rendered HTML table for better styling
            document.addEventListener('DOMContentLoaded', function() {
                const tables = document.querySelectorAll('.excel-card-body table');
                tables.forEach(table => {
                    table.classList.add('excel-table');

                    // Add text-end class to amount columns
                    const headers = table.querySelectorAll('thead th');
                    headers.forEach((th, index) => {
                        if (th.textContent.includes('Amount') ||
                            th.textContent.includes('Total') ||
                            th.textContent.includes('Price')) {
                            th.classList.add('text-end');

                            // Add class to corresponding td cells
                            const rows = table.querySelectorAll('tbody tr');
                            rows.forEach(row => {
                                const cell = row.querySelectorAll('td')[index];
                                if (cell) {
                                    cell.classList.add('amount-cell');
                                }
                            });
                        }
                    });
                });
            });
        </script>
    @endpush
@endsection
