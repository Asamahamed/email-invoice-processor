@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-file-alt me-2"></i>
                    Non-Credit Records
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
                
                @if(isset($emails) && $emails->count() > 0)
                    <!-- Stats Summary -->
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="stat-card">
                                <div class="stat-title">Total Records</div>
                                <h3 class="stat-value">{{ $emails->count() }}</h3>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="stat-card">
                                <div class="stat-title">Invoices Generated</div>
                                <h3 class="stat-value">{{ $emails->where('processing_status', 'invoice_generated')->count() }}</h3>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="stat-card">
                                <div class="stat-title">Pending Generation</div>
                                <h3 class="stat-value">{{ $emails->where('processing_status', '!=', 'invoice_generated')->count() }}</h3>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Info Alert -->
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Non-Credit (Sharmila):</strong> These invoices include a <strong>5% handling fee</strong> and are generated in <strong>INR currency</strong> with applicable GST (CGST + SGST).
                    </div>
                    
                    <!-- Search Filter -->
                    <div class="filter-section mb-4">
                        <form method="GET" action="{{ route('non-credit') }}" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="fas fa-search text-secondary"></i>
                                    </span>
                                    <input type="text" name="search" class="form-control border-start-0" 
                                           placeholder="Subject, Agent, Guest, Tour Ref..." 
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
                    
                    <!-- Records Table -->
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Email Date</th>
                                    <th>From</th>
                                    <th>Subject</th>
                                    <th>Travel Dates</th>
                                    <th>Agent Name</th>
                                    <th>Tour Ref</th>
                                    <th class="text-end">Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($emails as $index => $email)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        {{ $email->received_at ? $email->received_at->format('d/m/Y') : '-' }}<br>
                                        <small class="text-muted">{{ $email->received_at ? $email->received_at->format('H:i') : '' }}</small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $email->from_name ?: '-' }}</div>
                                        <small class="text-muted">{{ $email->from_email }}</small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ Str::limit($email->subject ?: '-', 50) }}</div>
                                        @if($email->is_tour_confirmation)
                                            <span class="badge-credit mt-1 d-inline-block">Confirmation</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($email->travel_start_date)
                                            {{ \Carbon\Carbon::parse($email->travel_start_date)->format('d/m/Y') }}
                                            @if($email->travel_end_date)
                                                <br><small>to {{ \Carbon\Carbon::parse($email->travel_end_date)->format('d/m/Y') }}</small>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $email->agent_name ?: '-' }}</div>
                                        @if($email->guest_name)
                                            <small class="text-muted">{{ $email->guest_name }}</small>
                                        @endif
                                    </td>
                                    <td><code>{{ $email->tour_ref ?: '-' }}</code></td>
                                    <td class="text-end fw-semibold">
                                        @if($email->total_amount)
                                            {{ $email->currency ?? 'USD' }} {{ number_format($email->total_amount, 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if($email->processing_status == 'invoice_generated')
                                            <span class="badge-credit">Invoiced</span>
                                        @else
                                            <span class="badge-pending">Pending</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            @if($email->processing_status == 'invoice_generated' && $email->invoice)
                                                <a href="{{ route('download', $email->invoice->id) }}" class="btn btn-accent btn-sm">
                                                    <i class="fas fa-download me-1"></i> PDF
                                                </a>
                                            @else
                                                <form action="{{ route('generate.invoice') }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="email_id" value="{{ $email->id }}">
                                                    <button type="submit" class="btn btn-primary btn-sm" 
                                                            onclick="return confirm('Generate invoice for this record?')">
                                                        <i class="fas fa-file-invoice me-1"></i> Generate
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                        <div class="text-muted small">
                            Showing {{ $emails->firstItem() ?? 0 }} to {{ $emails->lastItem() ?? 0 }} of {{ $emails->total() }} records
                        </div>
                        <div>
                            {{ $emails->appends(request()->query())->links() }}
                        </div>
                    </div>
                    
                @else
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <i class="fas fa-file-alt fa-4x text-secondary mb-3 d-block"></i>
                        <h5 class="text-secondary mb-3">No Non-Credit Records Found</h5>
                        <p class="text-muted mb-4">Non-credit records appear when emails are classified accordingly.</p>
                        <a href="{{ route('index') }}" class="btn btn-primary">
                            <i class="fas fa-sync-alt me-1"></i> Fetch Emails
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection