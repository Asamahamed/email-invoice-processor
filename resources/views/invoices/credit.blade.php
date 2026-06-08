@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-credit-card me-2"></i>
                    Credit Invoices
                </div>
                <a href="{{ route('index') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to Emails
                </a>
            </div>
            
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i> {{ session('success') }}
                    </div>
                @endif
                
                @if(session('error'))
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> {{ session('error') }}
                    </div>
                @endif
                
                @if(isset($invoices) && $invoices->count() > 0)
                    <!-- Stats Summary -->
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="stat-card">
                                <div class="stat-title">Total Invoices</div>
                                <h3 class="stat-value">{{ $invoices->count() }}</h3>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="stat-card">
                                <div class="stat-title">Total Value</div>
                                <h3 class="stat-value">{{ number_format($invoices->sum('grand_total'), 2) }}</h3>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="stat-card">
                                <div class="stat-title">Currency</div>
                                <h3 class="stat-value">USD</h3>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Search Filter -->
                    <div class="filter-section mb-4">
                        <form method="GET" action="{{ route('credit') }}" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="fas fa-search text-secondary"></i>
                                    </span>
                                    <input type="text" name="search" class="form-control border-start-0" 
                                           placeholder="Invoice #, Customer, Tour Ref..." 
                                           value="{{ request('search') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Invoices Table -->
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Invoice Number</th>
                                    <th>Date</th>
                                    <th>Customer / Agent</th>
                                    <th>Tour Reference</th>
                                    <th class="text-end">Amount</th>
                                    <th>Currency</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoices as $index => $invoice)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td><code>{{ $invoice->invoice_number }}</code></td>
                                    <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ $invoice->customer_name }}</div>
                                        @if($invoice->guest_name)
                                            <small class="text-muted">{{ $invoice->guest_name }}</small>
                                        @endif
                                    </td>
                                    <td><code class="text-accent">{{ $invoice->tour_ref ?: '-' }}</code></td>
                                    <td class="text-end fw-semibold">$ {{ number_format($invoice->grand_total, 2) }}</td>
                                    <td>{{ $invoice->currency }}</td>
                                    <td>
                                        <a href="{{ route('download', $invoice->id) }}" class="btn btn-accent btn-sm">
                                            <i class="fas fa-download me-1"></i> PDF
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                        <div class="text-muted small">
                            Showing {{ $invoices->firstItem() ?? 0 }} to {{ $invoices->lastItem() ?? 0 }} of {{ $invoices->total() }} invoices
                        </div>
                        <div>
                            {{ $invoices->appends(request()->query())->links() }}
                        </div>
                    </div>
                    
                @else
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <i class="fas fa-file-invoice fa-4x text-secondary mb-3 d-block"></i>
                        <h5 class="text-secondary mb-3">No Credit Invoices Found</h5>
                        <p class="text-muted mb-4">Generate invoices from emails marked as "Credit" type.</p>
                        <a href="{{ route('index') }}" class="btn btn-primary">
                            <i class="fas fa-envelope me-1"></i> Go to Emails
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection