@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-calendar-alt me-2"></i>Month Wise Report - {{ $summary['month_name'] }}</h5>
            <div>
                <a href="{{ route('reports.index') }}" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
                <a href="{{ route('reports.export.month-wise', ['month' => $month, 'year' => $year]) }}" 
                   class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="card-body">
            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-title">Total Invoices</div>
                        <h3 class="stat-value">{{ $summary['total_invoices'] }}</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-title">Total Amount</div>
                        <h3 class="stat-value">{{ $summary['currency'] }} {{ number_format($summary['total_amount'], 2) }}</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-title">Month</div>
                        <h3 class="stat-value">{{ $summary['month_name'] }}</h3>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section mb-4">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-select">
                            @for($m=1; $m<=12; $m++)
                                <option value="{{ sprintf('%02d', $m) }}" {{ $month == $m ? 'selected' : '' }}>
                                    {{ date('F', mktime(0,0,0,$m,1)) }}
                                </option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            @for($y=date('Y'); $y>=date('Y')-5; $y--)
                                <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </div>
                </form>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Month</th>
                            <th>Date</th>
                            <th>Invoice #</th>
                            <th>CNTL</th>
                            <th>Agent Name</th>
                            <th>Guest Name</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>File Handler</th>
                            <th>Tour Start Date</th>
                            <th>Travel Date</th>
                            <th>Sales Person</th>
                            <th>GST No</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reportData as $row)
                            <tr>
                                <td>{{ $row['month'] }}</td>
                                <td>{{ $row['date'] }}</td>
                                <td><strong>{{ $row['invoice_number'] }}</strong></td>
                                <td>{{ $row['tour_ref'] }}</td>
                                <td>{{ $row['agent_name'] }}</td>
                                <td>{{ $row['guest_name'] }}</td>
                                <td>{{ $row['currency'] }} {{ number_format($row['amount'], 2) }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td>{{ $row['file_handler'] }}</td>
                                <td>{{ $row['tour_start_date'] }}</td>
                                <td>{{ $row['travel_date'] }}</td>
                                <td>{{ $row['sales_person'] }}</td>
                                <td>{{ $row['gst_no'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="text-center">No invoices found for this month</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.25rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    text-align: center;
}
.stat-title {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
}
.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0.5rem 0 0 0;
}
</style>
@endsection