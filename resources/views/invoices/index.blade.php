@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <i class="fas fa-inbox me-2" style="color: var(--accent);"></i>
                        <span>Incoming Emails</span>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <form action="{{ route('process') }}" method="POST" style="display: inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-accent">
                                <i class="fas fa-sync-alt me-1"></i> Fetch & Process
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="card-body">
                @if (session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        {{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                @if (session('info'))
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        {{ session('info') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <!-- Stats Cards - All 4 in one row -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-title">Total Emails</div>
                            <div class="d-flex justify-content-between align-items-end">
                                <h3 class="stat-value mb-0">{{ $stats['total'] ?? 0 }}</h3>
                                <div class="stat-icon">
                                    <i class="fas fa-inbox"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-title">Credit Invoices</div>
                            <div class="d-flex justify-content-between align-items-end">
                                <h3 class="stat-value mb-0">{{ $stats['credit'] ?? 0 }}</h3>
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-title">Non-Credit</div>
                            <div class="d-flex justify-content-between align-items-end">
                                <h3 class="stat-value mb-0">{{ $stats['non_credit'] ?? 0 }}</h3>
                                <div class="stat-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-title">Invoices Generated</div>
                            <div class="d-flex justify-content-between align-items-end">
                                <h3 class="stat-value mb-0">{{ $stats['invoices'] ?? 0 }}</h3>
                                <div class="stat-icon">
                                    <i class="fas fa-file-invoice"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Action Buttons -->
              <!-- Quick Action Buttons -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('credit') }}" class="btn btn-accent">
                <i class="fas fa-credit-card me-1"></i> Credit Invoices
            </a>
            <a href="{{ route('non-credit') }}" class="btn btn-outline-primary">
                <i class="fas fa-file-alt me-1"></i> Non-Credit Invoices
            </a>
            <!-- Reports Button -->
            <a href="{{ route('reports.index') }}" class="btn btn-info">
                <i class="fas fa-chart-bar me-1"></i> Reports
            </a>
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
                                        placeholder="Subject, email, agent, guest..." value="{{ request('search') }}">
                                </div>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Credit Type</label>
                                <select name="credit_type" class="form-select">
                                    <option value="all" {{ request('credit_type') == 'all' ? 'selected' : '' }}>All
                                    </option>
                                    <option value="credit" {{ request('credit_type') == 'credit' ? 'selected' : '' }}>
                                        Credit</option>
                                    <option value="non_credit"
                                        {{ request('credit_type') == 'non_credit' ? 'selected' : '' }}>Non-Credit</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="read_status" class="form-select">
                                    <option value="all" {{ request('read_status') == 'all' ? 'selected' : '' }}>All
                                    </option>
                                    <option value="read" {{ request('read_status') == 'read' ? 'selected' : '' }}>Read
                                    </option>
                                    <option value="unread" {{ request('read_status') == 'unread' ? 'selected' : '' }}>
                                        Unread</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control"
                                    value="{{ request('date_from') }}">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control"
                                    value="{{ request('date_to') }}">
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i> Apply Filters
                                </button>
                                <a href="{{ route('index') }}" class="btn btn-secondary">
                                    <i class="fas fa-undo me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Emails Table -->
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
                                    <td class="subject-cell">
                                        <div class="fw-semibold subject-text">{{ $email->subject ?: '-' }}</div>
                                        @if ($email->is_tour_confirmation)
                                            <span class="badge-credit mt-1 d-inline-block">Confirmation</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($email->travel_start_date)
                                            {{ \Carbon\Carbon::parse($email->travel_start_date)->format('d/m/Y') }}
                                            @if ($email->travel_end_date)
                                                <br><small>to
                                                    {{ \Carbon\Carbon::parse($email->travel_end_date)->format('d/m/Y') }}</small>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>{{ $email->file_handler ?: '-' }}</td>
                                    <td class="fw-semibold">{{ $email->agent_name ?: '-' }}</td>
                                    <td>
                                        @if ($email->invoice_number && $email->invoice_number != 'NA')
                                            <code class="fw-bold text-primary">{{ $email->invoice_number }}</code>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($email->tour_ref && $email->tour_ref != 'NA')
                                            <code>{{ $email->tour_ref }}</code>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="fw-semibold">
                                        @if ($email->total_amount)
                                            {{ $email->currency ?? 'USD' }} {{ number_format($email->total_amount, 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if ($email->credit_type == 'non_credit')
                                            <span class="badge-non-credit">Non-Credit</span>
                                        @elseif($email->credit_type == 'credit')
                                            <span class="badge-credit">Credit</span>
                                        @else
                                            <span class="badge-pending">Pending</span>
                                        @endif
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <div class="action-buttons">
                                            @if (!$email->invoice)
                                                <button type="button" class="btn btn-primary btn-sm generate-invoice-btn"
                                                    data-email-id="{{ $email->id }}"
                                                    onclick="generateAndViewInvoice(this)">
                                                    <i class="fas fa-file-invoice"></i> Generate
                                                </button>
                                            @else
                                                <a href="{{ route('invoice.view', $email->invoice->id) }}"
                                                    class="btn btn-success btn-sm" target="_blank">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="{{ route('invoice.download', $email->invoice->id) }}"
                                                    class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                {{-- Remove this Revise button --}}
                                                {{-- <button type="button" class="btn btn-warning btn-sm regenerate-invoice-btn" ...> --}}
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="text-center py-5">
                                        <i class="fas fa-inbox fa-3x text-secondary mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No emails found</p>
                                        <button type="submit" form="fetchForm" class="btn btn-accent mt-3">
                                            <i class="fas fa-sync-alt me-1"></i> Fetch Emails
                                        </button>
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
                        <select class="form-select form-select-sm d-inline-block w-auto"
                            onchange="window.location.href=this.value">
                            <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 10])) }}"
                                {{ request('per_page') == 10 ? 'selected' : '' }}>10</option>
                            <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 20])) }}"
                                {{ request('per_page') == 20 ? 'selected' : '' }}>20</option>
                            <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 50])) }}"
                                {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                            <option value="{{ route('index', array_merge(request()->query(), ['per_page' => 100])) }}"
                                {{ request('per_page') == 100 ? 'selected' : '' }}>100</option>
                        </select>
                    </div>
                    <div>
                        {{ $emails->appends(request()->query())->links('pagination::bootstrap-5') }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Email View Modal -->
    <div class="modal fade" id="emailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-envelope me-2" style="color: var(--accent);"></i>
                        <span id="modalSubject">Email Details</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
            background-color: var(--hover-bg);
        }

        /* Email content styling */
        .email-content {
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

        .modal-header {
            background-color: white;
            border-bottom: 2px solid var(--accent);
        }

        /* Subject column - Full visibility with wrap */
        .subject-cell {
            max-width: 350px;
            min-width: 250px;
        }

        .subject-text {
            white-space: normal;
            word-wrap: break-word;
            word-break: break-word;
            line-height: 1.4;
            font-size: 0.8rem;
        }

        /* Responsive - on mobile */
        @media (max-width: 768px) {
            .subject-cell {
                max-width: 200px;
                min-width: 150px;
            }
        }

        /* For very long subjects */
        .subject-text {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            /* Max 3 lines, then ellipsis */
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Optional: Show full subject on hover */
        .subject-text:hover {
            -webkit-line-clamp: unset;
            background-color: #f8f9fa;
            position: relative;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 4px;
            border-radius: 4px;
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

                    $('#emailModal').modal('show');
                    $('#modalBody').html(
                        '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>'
                    );

                    $.ajax({
                        url: '{{ route('email.view') }}',
                        method: 'GET',
                        data: {
                            id: emailId
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#modalSubject').text(response.email.subject);
                                let emailContent = response.email.body ||
                                    '<p>No content available</p>';

                                $('#modalBody').html(`
                        <div class="email-meta">
                            <p><strong>From:</strong> ${escapeHtml(response.email.from_name || '-')} &lt;${escapeHtml(response.email.from_email)}&gt;</p>
                            <p><strong>Received:</strong> ${response.email.received_at}</p>
                            <p><strong>Subject:</strong> ${escapeHtml(response.email.subject)}</p>
                            ${response.email.agent_name ? `<p><strong>Agent:</strong> ${escapeHtml(response.email.agent_name)}</p>` : ''}
                            ${response.email.invoice_number ? `<p><strong>Invoice No:</strong> ${escapeHtml(response.email.invoice_number)}</p>` : ''}
                            ${response.email.tour_ref ? `<p><strong>Tour Ref:</strong> ${escapeHtml(response.email.tour_ref)}</p>` : ''}
                            ${response.email.file_handler ? `<p><strong>Sales Person:</strong> ${escapeHtml(response.email.file_handler)}</p>` : ''}
                        </div>
                        <div class="email-content">
                            ${emailContent}
                        </div>
                    `);
                            }
                        },
                        error: function() {
                            $('#modalBody').html(
                                '<div class="alert alert-danger m-3">Failed to load email content</div>'
                            );
                        }
                    });
                });

                // Generate invoice directly - NO MODAL
                $('#modalGenerateBtn').off('click').on('click', function() {
                    if (currentEmailId) {
                        generateInvoice(currentEmailId);
                    }
                });

                function escapeHtml(text) {
                    if (!text) return '';
                    return text.replace(/[&<>]/g, function(m) {
                        if (m === '&') return '&amp;';
                        if (m === '<') return '&lt;';
                        if (m === '>') return '&gt;';
                        return m;
                    });
                }
            });

            // Generate invoice from table row - DIRECT, NO MODAL
            function generateAndViewInvoice(button) {
                const emailId = $(button).data('email-id');
                generateInvoice(emailId, button);
            }

            function generateInvoice(emailId, button = null) {
                const $button = button ? $(button) : null;
                const originalHtml = $button ? $button.html() : '';

                if ($button) {
                    $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Checking...');
                }

                // Get email details - both invoice_number and tour_ref
                $.ajax({
                    url: '{{ route('get.email.invoice.number') }}',
                    method: 'GET',
                    data: {
                        email_id: emailId
                    },
                    dataType: 'json',
                    success: function(emailResponse) {
                        if (emailResponse.success) {
                            const invoiceNumber = emailResponse.invoice_number;
                            const tourRef = emailResponse.tour_ref;

                            // Check by invoice_number AND tour_ref
                            $.ajax({
                                url: '{{ route('check.invoice.by.number') }}',
                                method: 'GET',
                                data: {
                                    invoice_number: invoiceNumber,
                                    tour_ref: tourRef
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.exists) {
                                        // Create revision
                                        if ($button) {
                                            $button.html(
                                                '<i class="fas fa-spinner fa-spin"></i> Creating Revision...'
                                                );
                                        }

                                        $.ajax({
                                            url: '{{ route('regenerate.invoice') }}',
                                            method: 'POST',
                                            data: {
                                                email_id: emailId,
                                                _token: '{{ csrf_token() }}'
                                            },
                                            dataType: 'json',
                                            success: function(response) {
                                                if (response.success) {
                                                    window.open(
                                                        '{{ url('/invoice/view') }}/' +
                                                        response.invoice_id, '_blank');
                                                    toastr.success('✅ Revision R' + response
                                                        .revision_number + ' created!');
                                                    setTimeout(() => location.reload(),
                                                        1500);
                                                } else {
                                                    toastr.error(response.message ||
                                                        'Failed to create revision');
                                                    if ($button) $button.prop('disabled',
                                                        false).html(originalHtml);
                                                }
                                            },
                                            error: function(xhr) {
                                                let errorMsg = 'Error creating revision';
                                                if (xhr.responseJSON && xhr.responseJSON
                                                    .message) errorMsg = xhr.responseJSON
                                                    .message;
                                                toastr.error(errorMsg);
                                                if ($button) $button.prop('disabled', false)
                                                    .html(originalHtml);
                                            }
                                        });
                                    } else {
                                        // No invoice exists - create new
                                        if ($button) {
                                            $button.html(
                                                '<i class="fas fa-spinner fa-spin"></i> Generating...'
                                                );
                                        }

                                        $.ajax({
                                            url: '{{ route('generate.and.view.invoice') }}',
                                            method: 'POST',
                                            data: {
                                                email_id: emailId,
                                                _token: '{{ csrf_token() }}'
                                            },
                                            dataType: 'json',
                                            success: function(response) {
                                                if (response.success) {
                                                    window.open(
                                                        '{{ url('/invoice/view') }}/' +
                                                        response.invoice_id, '_blank');
                                                    toastr.success(
                                                        '✅ Invoice generated with auto GST!'
                                                        );
                                                    setTimeout(() => location.reload(),
                                                        1500);
                                                } else {
                                                    toastr.error(response.message ||
                                                        'Failed to generate invoice');
                                                    if ($button) $button.prop('disabled',
                                                        false).html(originalHtml);
                                                }
                                            },
                                            error: function(xhr) {
                                                let errorMsg = 'Error generating invoice';
                                                if (xhr.responseJSON && xhr.responseJSON
                                                    .message) errorMsg = xhr.responseJSON
                                                    .message;
                                                toastr.error(errorMsg);
                                                if ($button) $button.prop('disabled', false)
                                                    .html(originalHtml);
                                            }
                                        });
                                    }
                                },
                                error: function() {
                                    toastr.error('Error checking invoice by number');
                                    if ($button) $button.prop('disabled', false).html(originalHtml);
                                }
                            });
                        } else {
                            toastr.error('Could not get invoice number');
                            if ($button) $button.prop('disabled', false).html(originalHtml);
                        }
                    },
                    error: function() {
                        toastr.error('Error fetching email details');
                        if ($button) $button.prop('disabled', false).html(originalHtml);
                    }
                });
            }


            function regenerateInvoice(button) {
                const emailId = $(button).data('email-id');
                const originalHtml = $(button).html();
                const $button = $(button);

                // Get current revision count - use checkInvoice.by.number
                $.ajax({
                    url: '{{ route('get.email.invoice.number') }}',
                    method: 'GET',
                    data: {
                        email_id: emailId
                    },
                    dataType: 'json',
                    success: function(emailResponse) {
                        if (emailResponse.success) {
                            const invoiceNumber = emailResponse.invoice_number;

                            $.ajax({
                                url: '{{ route('check.invoice.by.number') }}',
                                method: 'GET',
                                data: {
                                    invoice_number: invoiceNumber
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.exists) {
                                        const nextRevision = response.revision_number + 1;
                                        if (!confirm('⚠️ This will create a REVISED invoice (R' +
                                                nextRevision + '). Continue?')) {
                                            return;
                                        }

                                        $button.prop('disabled', true).html(
                                            '<i class="fas fa-spinner fa-spin"></i> Creating Revision...'
                                            );

                                        $.ajax({
                                            url: '{{ route('regenerate.invoice') }}',
                                            method: 'POST',
                                            data: {
                                                email_id: emailId,
                                                _token: '{{ csrf_token() }}'
                                            },
                                            dataType: 'json',
                                            success: function(response) {
                                                if (response.success) {
                                                    window.open(
                                                        '{{ url('/invoice/view') }}/' +
                                                        response.invoice_id, '_blank');
                                                    toastr.success(response.message ||
                                                        '✅ Revision created successfully!'
                                                        );
                                                    setTimeout(() => location.reload(),
                                                        2000);
                                                } else {
                                                    toastr.error(response.message ||
                                                        'Failed to create revision');
                                                    $button.prop('disabled', false).html(
                                                        originalHtml);
                                                }
                                            },
                                            error: function(xhr) {
                                                let errorMsg = 'Error creating revision';
                                                if (xhr.responseJSON && xhr.responseJSON
                                                    .message) errorMsg = xhr.responseJSON
                                                    .message;
                                                toastr.error(errorMsg);
                                                $button.prop('disabled', false).html(
                                                    originalHtml);
                                            }
                                        });
                                    } else {
                                        toastr.error('No invoice found to revise');
                                    }
                                },
                                error: function() {
                                    toastr.error('Failed to check invoice status');
                                }
                            });
                        } else {
                            toastr.error('Could not get invoice number');
                        }
                    },
                    error: function() {
                        toastr.error('Failed to fetch email details');
                    }
                });
            }

            function getNextRevisionNumber(emailId) {
                // This is a helper - actual revision number is calculated on server
                return '?';
            }
        </script>
    @endpush
@endsection
