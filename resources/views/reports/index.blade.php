@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-chart-bar me-2"></i>Invoice Reports</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- Month Wise Report Card -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-calendar-alt fa-4x text-primary mb-3"></i>
                            <h4>Month Wise Report</h4>
                            <p class="text-muted">Generate report by selecting Month & Year</p>
                            <form action="{{ route('reports.month-wise') }}" method="GET">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <select name="month" class="form-select">
                                            @for($m=1; $m<=12; $m++)
                                                <option value="{{ sprintf('%02d', $m) }}" {{ date('m') == $m ? 'selected' : '' }}>
                                                    {{ date('F', mktime(0,0,0,$m,1)) }}
                                                </option>
                                            @endfor
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <select name="year" class="form-select">
                                            @for($y=date('Y'); $y>=date('Y')-5; $y--)
                                                <option value="{{ $y }}" {{ date('Y') == $y ? 'selected' : '' }}>{{ $y }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary mt-3">
                                    <i class="fas fa-file-alt me-1"></i> Generate Report
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Date Wise Report Card -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-calendar-range fa-4x text-success mb-3"></i>
                            <h4>Date Wise Report</h4>
                            <p class="text-muted">Generate report by selecting date range</p>
                            <form action="{{ route('reports.date-wise') }}" method="GET">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label small">From</label>
                                        <input type="date" name="start_date" class="form-control" 
                                               value="{{ date('Y-m-01') }}">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">To</label>
                                        <input type="date" name="end_date" class="form-control" 
                                               value="{{ date('Y-m-t') }}">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-success mt-3">
                                    <i class="fas fa-file-alt me-1"></i> Generate Report
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection