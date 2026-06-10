@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h3 class="mb-0">📧 Incoming Emails</h3>
                <div class="mt-2 mt-sm-0">
                    <form action="{{ route('process') }}" method="POST" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-light btn-sm">
                            🔄 Fetch & Process Emails
                        </button>
                    </form>
                    <a href="{{ route('non-credit') }}" class="btn btn-warning btn-sm">
                        📄 Non-Credit Invoices
                    </a>
                    <a href="{{ route('credit') }}" class="btn btn-success btn-sm">
                        📄 Credit Invoices
                    </a>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            
            @if(session('info'))
                <div class="alert alert-info">{{ session('info') }}</div>
            @endif
            
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            
            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-title">Total Emails</div>
                                <h3 class="stat-value">{{ $stats['total'] ?? 0 }}</h3>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-inbox"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-title">Credit</div>
                                <h3 class="stat-value">{{ $stats['credit'] ?? 0 }}</h3>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-title">Non-Credit</div>
                                <h3 class="stat-value">{{ $stats['non_credit'] ?? 0 }}</h3>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-title">Invoices</div>
                                <h3 class="stat-value">{{ $stats['invoices'] ?? 0 }}</h3>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section mb-4">
                <form method="GET" action="{{ route('index') }}" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="fas fa-search text-secondary"></i>
                                </span>
                                <input type="text" name="search" class="form-control border-start-0" 
                                       placeholder="Subject, email, agent, guest..." 
                                       value="{{ request('search') }}">
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Credit Type</label>
                            <select name="credit_type" class="form-select">
                                <option value="all" {{ request('credit_type') == 'all' ? 'selected' : '' }}>All</option>
                                <option value="credit" {{ request('credit_type') == 'credit' ? 'selected' : '' }}>Credit</option>
                                <option value="non_credit" {{ request('credit_type') == 'non_credit' ? 'selected' : '' }}>Non-Credit</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="read_status" class="form-select">
                                <option value="all" {{ request('read_status') == 'all' ? 'selected' : '' }}>All</option>
                                <option value="read" {{ request('read_status') == 'read' ? 'selected' : '' }}>Read</option>
                                <option value="unread" {{ request('read_status') == 'unread' ? 'selected' : '' }}>Unread</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-1"></i> Apply
                            </button>
                            <a href="{{ route('index') }}" class="btn btn-secondary">
                                <i class="fas fa-undo me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Emails Table - Make rows clickable -->
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Received</th>
                            <th>From</th>
                            <th>Subject</th>
                            <th>Travel Dates</th>
                            <th>Handler</th>
                            <th>Agent</th>
                            <th>Invoice No</th>
                            <th>Tour Ref</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($emails as $index => $email)
                        <tr class="email-row" data-email-id="{{ $email->id }}" style="cursor: pointer;">
                            <td>{{ $emails->firstItem() + $index }}</td>
                            <td>
                                {{ $email->received_at->format('d/m/Y') }}<br>
                                <small class="text-muted">{{ $email->received_at->format('H:i') }}</small>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $email->from_name ?: '-' }}</div>
                                <small class="text-muted">{{ $email->from_email }}</small>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ Str::limit($email->subject ?: '-') }}</div>
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
                            <td>{{ $email->file_handler ?: '-' }}</td>
                            <td class="fw-semibold">{{ $email->agent_name ?: '-' }}</td>
                          <td>
    @if($email->invoice)
        <code class="fw-bold text-primary">{{ $email->invoice->invoice_number }}</code>
    @elseif($email->invoice_number && $email->invoice_number != 'NA')
        <code class="fw-bold text-primary">{{ $email->invoice_number }}</code>
    @else
        <span class="text-muted">-</span>
    @endif
</td>
<td>
    @if($email->tour_ref && $email->tour_ref != 'NA')
        <code>{{ $email->tour_ref }}</code>
    @else
        <span class="text-muted">-</span>
    @endif
</td>
                            <td class="fw-semibold">
                                @if($email->total_amount)
                                    {{ $email->currency ?? 'USD' }} {{ number_format($email->total_amount, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if($email->credit_type == 'non_credit')
                                    <span class="badge-non-credit">Non-Credit</span>
                                @elseif($email->credit_type == 'credit')
                                    <span class="badge-credit">Credit</span>
                                @else
                                    <span class="badge-pending">Pending</span>
                                @endif
                            </td>
                            <td onclick="event.stopPropagation()">
                                <div class="action-buttons">
                                    <form action="{{ route('generate.invoice') }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="email_id" value="{{ $email->id }}">
                                        <button type="submit" class="btn btn-primary btn-sm" 
                                                onclick="return confirm('Generate invoice?')">
                                            <i class="fas fa-file-invoice"></i>
                                        </button>
                                    </form>
                                    
                                    @if($email->invoice)
                                        <a href="{{ route('download', $email->invoice->id) }}" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="12" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-secondary mb-3 d-block"></i>
                                <p class="text-muted mb-0">No emails found</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                <div>
                    <label class="text-muted me-2 small">Show:</label>
                    <select class="form-select form-select-sm d-inline-block w-auto" onchange="window.location.href=this.value">
                        <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 10])) }}" {{ request('per_page') == 10 ? 'selected' : '' }}>10</option>
                        <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 20])) }}" {{ request('per_page') == 20 ? 'selected' : '' }}>20</option>
                        <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 50])) }}" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                        <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 100])) }}" {{ request('per_page') == 100 ? 'selected' : '' }}>100</option>
                    </select>
                </div>
                <div>
                    {{ $emails->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Email View Modal -->
<div class="modal fade" id="emailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-envelope me-2"></i>
                    <span id="modalSubject">Email Details</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="modalGenerateBtn">
                    <i class="fas fa-file-invoice me-1"></i> Generate Invoice
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Make rows clickable */
    .email-row {
        cursor: pointer;
        transition: background-color 0.2s ease;
    }
    .email-row:hover {
        background-color: rgba(102, 126, 234, 0.05);
    }
    
    /* Email content styling */
    .email-content {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 14px;
        line-height: 1.6;
        white-space: pre-wrap;
        word-wrap: break-word;
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        max-height: 500px;
        overflow-y: auto;
    }
    
    .email-meta {
        background: #e9ecef;
        padding: 12px 15px;
        border-radius: 8px;
        margin-bottom: 15px;
    }
    
    .email-meta p {
        margin-bottom: 5px;
    }
    
    .email-meta strong {
        color: #495057;
        width: 100px;
        display: inline-block;
    }
</style>

@push('scripts')
<script>
$(document).ready(function() {
    let currentEmailId = null;
    
    // Click on email row to view full content
    $('.email-row').click(function() {
        const emailId = $(this).data('email-id');
        currentEmailId = emailId;
        
        // Show modal with loading
        $('#emailModal').modal('show');
        $('#modalBody').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        
        // Fetch email details
        $.ajax({
            url: '{{ route("email.view") }}',
            method: 'GET',
            data: { id: emailId },
           success: function(response) {
    if (response.success) {
        $('#modalSubject').text(response.email.subject);
        
        // Display HTML content directly (safe because it's from trusted source)
        let emailContent = response.email.body || '<p>No content available</p>';
        
        $('#modalBody').html(`
            <div class="email-meta">
                <p><strong>From:</strong> ${escapeHtml(response.email.from_name || '-')} &lt;${escapeHtml(response.email.from_email)}&gt;</p>
                <p><strong>Received:</strong> ${response.email.received_at}</p>
                <p><strong>Subject:</strong> ${escapeHtml(response.email.subject)}</p>
                ${response.email.agent_name ? `<p><strong>Agent:</strong> ${escapeHtml(response.email.agent_name)}</p>` : ''}
               ${response.email.invoice_number ? `<p><strong>Invoice No:</strong> ${escapeHtml(response.email.invoice_number)}</p>` : ''}
${response.email.tour_ref ? `<p><strong>Tour Ref:</strong> ${escapeHtml(response.email.tour_ref)}</p>` : ''}
            </div>
            <div class="email-content">
                ${emailContent}
            </div>
        `);
    }
},
            error: function() {
                $('#modalBody').html('<div class="alert alert-danger">Failed to load email content</div>');
            }
        });
    });
    
    // Generate invoice from modal
    $('#modalGenerateBtn').click(function() {
        if (currentEmailId) {
            let form = $('<form action="{{ route("generate.invoice") }}" method="POST"></form>');
            form.append('<input type="hidden" name="_token" value="{{ csrf_token() }}">');
            form.append('<input type="hidden" name="email_id" value="' + currentEmailId + '">');
            $('body').append(form);
            form.submit();
        }
    });
    
    // Helper functions
    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function nl2br(text) {
        if (!text) return '';
        return text.replace(/\n/g, '<br>');
    }
});
</script>
@endpush

@endsection