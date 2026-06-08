{{-- resources/views/pnl/index.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header bg-info text-white">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h3 class="mb-0">📊 Profit & Loss (PnL) - Vendor Expenses</h3>
                <div class="mt-2 mt-sm-0">
                    <form action="{{ route('pnl.fetch') }}" method="POST" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-light btn-sm">
                            🔄 Fetch PnL Emails
                        </button>
                    </form>
                    <a href="{{ route('pnl.export') }}" class="btn btn-success btn-sm">
                        📥 Export to Excel
                    </a>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-title">Total Records</div>
                        <h3 class="stat-value">{{ $stats['total'] ?? 0 }}</h3>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-title">Total Amount (SGD)</div>
                        <h3 class="stat-value">$ {{ number_format($stats['total_amount'] ?? 0, 2) }}</h3>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-title">Pending</div>
                        <h3 class="stat-value">{{ $stats['pending'] ?? 0 }}</h3>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-title">Approved</div>
                        <h3 class="stat-value">{{ $stats['approved'] ?? 0 }}</h3>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section mb-4">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control" placeholder="Search vendor, invoice, IS number..." value="{{ request('search') }}">
                    </div>
                    <div class="col-md-2">
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat }}" {{ request('category') == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="country" class="form-select">
                            <option value="">All Countries</option>
                            @foreach($countries as $code => $name)
                                <option value="{{ $code }}" {{ request('country') == $code ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="read_filter" class="form-select">
                            <option value="">All Status</option>
                            <option value="read" {{ request('read_filter') == 'read' ? 'selected' : '' }}>Read</option>
                            <option value="unread" {{ request('read_filter') == 'unread' ? 'selected' : '' }}>Unread</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Approval</option>
                            <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                            <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="{{ route('pnl.index') }}" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>
            
            <!-- Table with clickable rows -->
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Date</th>
                            <th>From</th>
                            <th>Vendor Name</th>
                            <th>Subject</th>
                            <th>IS Number</th>
                            <th>Invoice #</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pnlRecords as $record)
                        <tr class="pnl-row" data-id="{{ $record->id }}" style="cursor: pointer;">
                            <td>{{ $record->sno ?? $loop->iteration }}</td>
                            <td>{{ $record->received_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <div class="small">{{ $record->from_address ?: '-' }}</div>
                                <div class="text-muted small">{{ $record->from_email }}</div>
                            </td>
                            <td>
                                <div>{{ $record->vendor_name ?: '-' }}</div>
                                @if($record->country_code)
                                    <span class="badge bg-secondary">{{ $record->country_code }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 200px;">{{ Str::limit($record->subject, 50) }}</div>
                            </td>
                            <td><code>{{ $record->is_number ?: '-' }}</code></td>
                            <td>{{ $record->invoice_number ?: '-' }}</td>
                            <td>
                                @if($record->category == 'Multi')
                                    <span class="badge bg-info">Multiple</span>
                                @else
                                    <span class="badge bg-secondary">{{ $record->category ?: 'Other' }}</span>
                                @endif
                            </td>
                            <td class="fw-bold">
                                @php
                                    $displayAmount = $record->currency == 'SGD' ? $record->amount : ($record->amount * ($record->exchange_rate_used ?? 1));
                                @endphp
                                {{ number_format($displayAmount, 2) }}
                            </td>
                            <td>{{ $record->currency ?: 'SGD' }}</td>
                            <td>
                                @if($record->read_status == 'unread')
                                    <span class="badge bg-info">Unread</span>
                                @else
                                    <span class="badge bg-success">Read</span>
                                @endif
                                <br>
                                {{-- @if($record->status == 'pending')
                                    <span class="badge bg-warning mt-1">Pending</span>
                                @elseif($record->status == 'approved')
                                    <span class="badge bg-success mt-1">Approved</span>
                                @else
                                    <span class="badge bg-danger mt-1">Rejected</span>
                                @endif --}}
                            </td>
                            <!-- In the actions column, add this button next to existing ones -->
<td>
    <button class="btn btn-sm btn-success update-pnl-btn" data-id="{{ $record->id }}" data-bs-toggle="tooltip" title="Update PnL to Excel">
        <i class="fas fa-file-excel"></i> Update PnL
    </button>
    {{-- <button class="btn btn-sm btn-info view-items-btn" data-id="{{ $record->id }}" data-bs-toggle="modal" data-bs-target="#itemsModal">
        <i class="fas fa-list"></i>
    </button> --}}
    <!-- ... existing status dropdown ... -->
</td>
                            {{-- <td>
                                <button class="btn btn-sm btn-info view-items-btn" data-id="{{ $record->id }}" data-bs-toggle="modal" data-bs-target="#itemsModal">
                                    <i class="fas fa-list"></i>
                                </button>
                                <form action="{{ route('pnl.update-status', $record->id) }}" method="POST" style="display: inline;">
                                    @csrf
                                    <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()" style="width: 70px;">
                                        <option value="pending" {{ $record->status == 'pending' ? 'selected' : '' }}>Pend</option>
                                        <option value="approved" {{ $record->status == 'approved' ? 'selected' : '' }}>App</option>
                                        <option value="rejected" {{ $record->status == 'rejected' ? 'selected' : '' }}>Rej</option>
                                    </select>
                                </form>
                            </td> --}}
                        </tr>
                        @empty
                        <tr>
                            <td colspan="12" class="text-center py-5">No PnL records found</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            {{ $pnlRecords->links() }}
        </div>
    </div>
</div>

<!-- Items Modal -->
<div class="modal fade" id="itemsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-list-ul me-2"></i>PnL Line Items</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="itemsModalBody">
                <div class="text-center py-5"><div class="spinner-border"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="pnlEmailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>Email Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="pnlModalBody">
                <div class="text-center py-5"><div class="spinner-border text-info"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .pnl-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .pnl-row:hover {
        background-color: rgba(13, 148, 136, 0.1);
    }
    .email-content {
        max-height: 70vh;
        overflow-y: auto;
        padding: 20px;
    }
    .email-content table {
        border-collapse: collapse;
        width: 100%;
    }
    .email-content table td, .email-content table th {
        border: 1px solid #ddd;
        padding: 8px;
    }
    /* Fix for button inside clickable row */
    .pnl-row .btn,
    .pnl-row select,
    .pnl-row form {
        position: relative;
        z-index: 10;
    }
</style>

@push('scripts')
<script>
    // Add this to your existing script section
$('.update-pnl-btn').on('click', function(e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const btn = $(this);
    
    if (!confirm('This will parse the email and update the PnL Excel file. Continue?')) {
        return;
    }
    
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
    
    $.ajax({
        url: '{{ route("pnl.update-excel") }}',
        method: 'POST',
        data: {
            id: id,
            _token: '{{ csrf_token() }}'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                toastr.success(response.message || 'PnL updated successfully!');
                // Reload the items modal if open
                if ($('#itemsModal').hasClass('show')) {
                    $('.view-items-btn[data-id="' + id + '"]').click();
                }
            } else {
                toastr.error(response.message || 'Failed to update PnL');
            }
        },
        error: function(xhr) {
            let errorMsg = 'Error processing PnL';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
            }
            toastr.error(errorMsg);
        },
        complete: function() {
            btn.prop('disabled', false).html('<i class="fas fa-file-excel"></i> Update PnL');
        }
    });
});
// Wait for jQuery to be ready
$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    
    // Make entire row clickable to view email - Using jQuery for better compatibility
    $('.pnl-row').on('click', function(e) {
        // Don't trigger if clicking on buttons or selects
        if ($(e.target).is('button') || $(e.target).is('select') || $(e.target).closest('button').length || $(e.target).closest('select').length) {
            return;
        }
        
        const id = $(this).data('id');
        if (!id) return;
        
        console.log('Row clicked, ID:', id);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('pnlEmailModal'));
        const modalBody = document.getElementById('pnlModalBody');
        
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="mt-2">Loading email content...</p></div>';
        modal.show();
        
        // Fetch email content
        $.ajax({
            url: '/pnl/view-email/' + id,
            method: 'GET',
            dataType: 'json',
           success: function(data) {
    if (data.success) {
        let emailContent = data.email.body_html || data.email.body || '<p class="text-muted">No content available</p>';
        
        // Remove everything after "Thanks" or signature
        emailContent = emailContent.replace(/Thanks[^<]*<br\s*\/?>.*$/is, '');
        emailContent = emailContent.replace(/Warm Regards[^<]*<br\s*\/?>.*$/is, '');
        emailContent = emailContent.replace(/Best Regards[^<]*<br\s*\/?>.*$/is, '');
        emailContent = emailContent.replace(/Company Logo.*$/is, '');
        emailContent = emailContent.replace(/Website \| LinkedIn \| Facebook \| Instagram.*$/is, '');
        emailContent = emailContent.replace(/Countries of Operation[^<]*$/i, '');
        
        modalBody.innerHTML = '<div class="email-content">' + emailContent + '</div>';
    }
},
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                console.error('Response:', xhr.responseText);
                modalBody.innerHTML = '<div class="alert alert-danger m-3">Error loading email: ' + error + '</div>';
            }
        });
    });
    
    // View Items button
    $('.view-items-btn').on('click', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        const modalBody = document.getElementById('itemsModalBody');
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div><p class="mt-2">Loading items...</p></div>';
        
        $.ajax({
            url: '/pnl/items/' + id,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data.success && data.items && data.items.length) {
                    let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Type</th><th>Name/Description</th><th class="text-end">Amount</th><th>Currency</th></tr></thead><tbody>';
                    $.each(data.items, function(index, item) {
                        const itemName = item.hotel_name || item.transport_name || item.service_name || '-';
                        html += '<tr>' +
                            '<td><span class="badge bg-secondary">' + item.type + '</span></td>' +
                            '<td>' + itemName + '</td>' +
                            '<td class="text-end fw-bold">' + parseFloat(item.amount_original).toLocaleString() + '</td>' +
                            '<td>' + item.currency + '</td>' +
                            '</tr>';
                    });
                    html += '</tbody></table></div>';
                    modalBody.innerHTML = html;
                } else {
                    modalBody.innerHTML = '<div class="alert alert-info m-3">No line items found for this record.</div>';
                }
            },
            error: function() {
                modalBody.innerHTML = '<div class="alert alert-danger m-3">Error loading items. Please try again.</div>';
            }
        });
    });
});
</script>
@endpush
@endsection