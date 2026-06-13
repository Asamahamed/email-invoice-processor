{{-- resources/views/pnl/index.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="pnl-container">
    <!-- Header Section -->
    <div class="pnl-header mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div>
                <h1 class="pnl-title">
                    <i class="fas fa-chart-line me-2"></i> Profit & Loss Dashboard
                </h1>
                <p class="pnl-subtitle">Vendor Expenses & Financial Overview</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn-pnl btn-pnl-primary" onclick="event.preventDefault(); document.getElementById('fetch-form').submit();">
                    <i class="fas fa-sync-alt me-1"></i> Fetch Emails
                </button>
                <a href="{{ route('pnl.export') }}" class="btn-pnl btn-pnl-success">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </a>
                <form id="fetch-form" action="{{ route('pnl.fetch') }}" method="POST" class="d-none">
                    @csrf
                </form>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-5">
        <div class="col-md-3 col-sm-6">
            <div class="stat-card stat-card-primary">
                <div class="stat-card-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Total Records</span>
                    <h3 class="stat-card-value">{{ number_format($stats['total'] ?? 0) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card stat-card-success">
                <div class="stat-card-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Total Amount</span>
                    <h3 class="stat-card-value">${{ number_format($stats['total_amount'] ?? 0, 2) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card stat-card-warning">
                <div class="stat-card-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Pending</span>
                    <h3 class="stat-card-value">{{ number_format($stats['pending'] ?? 0) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card stat-card-info">
                <div class="stat-card-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Approved</span>
                    <h3 class="stat-card-value">{{ number_format($stats['approved'] ?? 0) }}</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-card mb-4">
        <div class="filter-card-header">
            <i class="fas fa-filter me-2"></i> Filter Records
        </div>
        <div class="filter-card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="filter-label">Search</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control form-control-pnl" 
                            placeholder="Vendor, Invoice, IS number..." value="{{ request('search') }}">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Category</label>
                    <select name="category" class="form-select form-select-pnl">
                        <option value="">All Categories</option>
                        @foreach ($categories ?? [] as $cat)
                            <option value="{{ $cat }}" {{ request('category') == $cat ? 'selected' : '' }}>
                                {{ $cat }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Country</label>
                    <select name="country" class="form-select form-select-pnl">
                        <option value="">All Countries</option>
                        @foreach ($countries ?? [] as $code => $name)
                            <option value="{{ $code }}" {{ request('country') == $code ? 'selected' : '' }}>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Read Status</label>
                    <select name="read_filter" class="form-select form-select-pnl">
                        <option value="">All</option>
                        <option value="read" {{ request('read_filter') == 'read' ? 'selected' : '' }}>Read</option>
                        <option value="unread" {{ request('read_filter') == 'unread' ? 'selected' : '' }}>Unread</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Approval Status</label>
                    <select name="status" class="form-select form-select-pnl">
                        <option value="">All</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                        <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-pnl" value="{{ request('date_from') }}">
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-pnl" value="{{ request('date_to') }}">
                </div>
                <div class="col-12">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-pnl btn-pnl-primary">
                            <i class="fas fa-search me-1"></i> Apply Filters
                        </button>
                        <a href="{{ route('pnl.index') }}" class="btn-pnl btn-pnl-secondary">
                            <i class="fas fa-undo-alt me-1"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Section -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="pnl-table">
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Date</th>
                        <th>From</th>
                        <th>Vendor</th>
                        <th>Subject</th>
                        <th>Tour ref</th>
                        <th>Invoice #</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pnlRecords as $record)
                        <tr class="pnl-row" data-id="{{ $record->id }}">
                            <td data-label="S.No">{{ $record->sno ?? $loop->iteration }}</td>
                            <td data-label="Date">
                                <span class="date-badge">{{ $record->received_at->format('d/m/Y') }}</span>
                                <small class="time-text">{{ $record->received_at->format('H:i') }}</small>
                            </td>
                            <td data-label="From">
                                <div class="vendor-info">
                                    <strong>{{ $record->from_address ?: '-' }}</strong>
                                    <small>{{ $record->from_email }}</small>
                                </div>
                            </td>
                            <td data-label="Vendor">
                                <div class="vendor-info">
                                    <strong>{{ $record->vendor_name ?: '-' }}</strong>
                                    @if ($record->country_code)
                                        <span class="country-badge">{{ $record->country_code }}</span>
                                    @endif
                                </div>
                            </td>
                            <td data-label="Subject">
                                <div class="subject-text" title="{{ $record->subject }}">
                                    {{ Str::limit($record->subject, 40) }}
                                </div>
                            </td>
                            <td data-label="Tour ref">
                                <code class="is-number">{{ $record->tour_ref ?: '-' }}</code>
                            </td>
                            <td data-label="Invoice #">
                                <code class="invoice-number">{{ $record->invoice_number ?: '-' }}</code>
                            </td>
                            <td data-label="Category">
                                @if ($record->category == 'Multi')
                                    <span class="badge-category badge-multi">Multiple</span>
                                @elseif($record->category)
                                    <span class="badge-category badge-default">{{ $record->category }}</span>
                                @else
                                    <span class="badge-category badge-other">Other</span>
                                @endif
                            </td>
                            <td data-label="Amount">
                                <div class="amount-value">${{ number_format($record->amount, 2) }}</div>
                            </td>
                            <td data-label="Status">
                                @if ($record->read_status == 'unread')
                                    <span class="status-badge status-unread">Unread</span>
                                @else
                                    <span class="status-badge status-read">Read</span>
                                @endif
                            </td>
                            <td data-label="Actions">
                                <div class="action-buttons">
                                    <button class="action-btn action-btn-warning update-pnl-btn" 
                                            data-id="{{ $record->id }}" title="Update PnL">
                                        <i class="fas fa-file-excel"></i>
                                    </button>
                                    <a href="{{ route('pnl.view-excel', $record->country_code ?? 'SG') }}" 
                                       class="action-btn action-btn-info" target="_blank" title="View PnL Sheet">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="empty-state">
                                <i class="fas fa-inbox mb-3" style="font-size: 48px; opacity: 0.5;"></i>
                                <p>No PnL records found</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($pnlRecords->hasPages())
           <!-- Pagination -->
<div class="pagination-wrapper">
    @if($pnlRecords->hasPages())
        <nav aria-label="Page navigation">
            <ul class="pagination">
                {{-- Previous Page Link --}}
                @if ($pnlRecords->onFirstPage())
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link">&laquo; Previous</span>
                    </li>
                @else
                    <li class="page-item">
                        <a class="page-link" href="{{ $pnlRecords->previousPageUrl() }}" rel="prev">&laquo; Previous</a>
                    </li>
                @endif

                {{-- Pagination Elements --}}
                @php
                    $start = max(1, $pnlRecords->currentPage() - 2);
                    $end = min($start + 4, $pnlRecords->lastPage());
                    if ($end - $start < 4 && $start > 1) {
                        $start = max(1, $end - 4);
                    }
                @endphp

                @if ($start > 1)
                    <li class="page-item">
                        <a class="page-link" href="{{ $pnlRecords->url(1) }}">1</a>
                    </li>
                    @if ($start > 2)
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    @endif
                @endif

                @for ($page = $start; $page <= $end; $page++)
                    @if ($page == $pnlRecords->currentPage())
                        <li class="page-item active" aria-current="page">
                            <span class="page-link">{{ $page }}</span>
                        </li>
                    @else
                        <li class="page-item">
                            <a class="page-link" href="{{ $pnlRecords->url($page) }}">{{ $page }}</a>
                        </li>
                    @endif
                @endfor

                @if ($end < $pnlRecords->lastPage())
                    @if ($end < $pnlRecords->lastPage() - 1)
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    @endif
                    <li class="page-item">
                        <a class="page-link" href="{{ $pnlRecords->url($pnlRecords->lastPage()) }}">{{ $pnlRecords->lastPage() }}</a>
                    </li>
                @endif

                {{-- Next Page Link --}}
                @if ($pnlRecords->hasMorePages())
                    <li class="page-item">
                        <a class="page-link" href="{{ $pnlRecords->nextPageUrl() }}" rel="next">Next &raquo;</a>
                    </li>
                @else
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link">Next &raquo;</span>
                    </li>
                @endif
            </ul>
        </nav>
    @endif
</div>
        @endif
    </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="pnlEmailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-envelope me-2"></i>Email Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="pnlModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* PnL Page Styles */
.pnl-container {
    max-width: 1400px;
    margin: 0 auto;
}

/* Header Styles */
.pnl-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-radius: 20px;
    padding: 1.75rem 2rem;
    color: white;
}

.pnl-title {
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0 0 0.25rem 0;
}

.pnl-subtitle {
    font-size: 0.85rem;
    opacity: 0.8;
    margin: 0;
}

/* Button Styles */
.btn-pnl {
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

.btn-pnl-primary {
    background-color: #0d9488;
    color: white;
}

.btn-pnl-primary:hover {
    background-color: #0f766e;
    transform: translateY(-1px);
    color: white;
}

.btn-pnl-success {
    background-color: #10b981;
    color: white;
}

.btn-pnl-success:hover {
    background-color: #059669;
    transform: translateY(-1px);
    color: white;
}

.btn-pnl-secondary {
    background-color: #64748b;
    color: white;
}

.btn-pnl-secondary:hover {
    background-color: #475569;
    color: white;
}

/* Stat Cards */
.stat-card {
    background: white;
    border-radius: 20px;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.2s ease;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.stat-card-icon {
    width: 55px;
    height: 55px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-card-primary .stat-card-icon {
    background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
    color: white;
}

.stat-card-success .stat-card-icon {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.stat-card-warning .stat-card-icon {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.stat-card-info .stat-card-icon {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.stat-card-content {
    flex: 1;
}

.stat-card-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    display: block;
    margin-bottom: 0.25rem;
}

.stat-card-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

/* Filter Card */
.filter-card {
    background: white;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.filter-card-header {
    padding: 1rem 1.5rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-weight: 600;
    font-size: 0.85rem;
    color: #1e293b;
}

.filter-card-body {
    padding: 1.5rem;
}

.filter-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    display: block;
    margin-bottom: 0.4rem;
}

.form-control-pnl, .form-select-pnl {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.5rem 0.75rem;
    font-size: 0.8rem;
    transition: all 0.2s ease;
}

.form-control-pnl:focus, .form-select-pnl:focus {
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
}

.input-group-text {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-right: none;
    color: #64748b;
}

/* Table Card */
.table-card {
    background: white;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.pnl-table {
    width: 100%;
    border-collapse: collapse;
}

.pnl-table thead th {
    padding: 1rem 1rem;
    background: #f8fafc;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    border-bottom: 1px solid #e2e8f0;
}

.pnl-table tbody td {
    padding: 1rem 1rem;
    font-size: 0.8rem;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.pnl-row {
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.pnl-row:hover {
    background-color: #f8fafc;
}

/* Badge Styles */
.date-badge {
    font-weight: 600;
    display: block;
}

.time-text {
    font-size: 0.65rem;
    color: #64748b;
}

.vendor-info {
    display: flex;
    flex-direction: column;
}

.vendor-info strong {
    font-size: 0.8rem;
}

.vendor-info small {
    font-size: 0.65rem;
    color: #64748b;
}

.country-badge {
    display: inline-block;
    padding: 0.15rem 0.4rem;
    background: #e2e8f0;
    border-radius: 4px;
    font-size: 0.6rem;
    font-weight: 600;
    margin-top: 0.2rem;
}

.subject-text {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.is-number, .invoice-number {
    background: #f1f5f9;
    padding: 0.2rem 0.4rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 500;
}

.badge-category {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 500;
}

.badge-default {
    background: #e0f2fe;
    color: #0369a1;
}

.badge-multi {
    background: #fef3c7;
    color: #92400e;
}

.badge-other {
    background: #f1f5f9;
    color: #475569;
}

.amount-value {
    font-weight: 700;
    color: #1e293b;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 500;
}

.status-unread {
    background: #fee2e2;
    color: #991b1b;
}

.status-read {
    background: #d1fae5;
    color: #065f46;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}

.action-btn-warning {
    background: #fef3c7;
    color: #d97706;
}

.action-btn-warning:hover {
    background: #f59e0b;
    color: white;
}

.action-btn-info {
    background: #e0f2fe;
    color: #0284c7;
}

.action-btn-info:hover {
    background: #0ea5e9;
    color: white;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem !important;
    color: #64748b;
}

.empty-state p {
    margin: 0;
}

/* Pagination */
.pagination-wrapper {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid #e2e8f0;
}

/* Modal Styles */
.modal-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: white;
    border: none;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

/* Responsive */
@media (max-width: 768px) {
    .pnl-header {
        padding: 1.25rem;
    }
    
    .pnl-title {
        font-size: 1.25rem;
    }
    
    .stat-card-value {
        font-size: 1.25rem;
    }
    
    .stat-card-icon {
        width: 45px;
        height: 45px;
        font-size: 1.25rem;
    }
    
    .pnl-table thead {
        display: none;
    }
    
    .pnl-table tbody tr {
        display: block;
        margin-bottom: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.75rem;
    }
    
    .pnl-table tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        border: none;
    }
    
    .pnl-table tbody td:before {
        content: attr(data-label);
        font-weight: 600;
        font-size: 0.7rem;
        color: #64748b;
        margin-right: 1rem;
    }
    
    .action-buttons {
        justify-content: flex-end;
    }
}
/* Pagination Styles - Override Bootstrap properly */
.pagination-wrapper {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: center;
}

.pagination {
    display: flex;
    gap: 5px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.pagination .page-item {
    display: inline-block;
    margin: 0;
}

.pagination .page-link {
    padding: 0.5rem 1rem;
    font-size: 0.8rem;
    font-weight: 500;
    color: #1e293b;
    background-color: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.2s ease;
}

.pagination .page-link:hover {
    background-color: #f1f5f9;
    border-color: #0d9488;
    color: #0d9488;
}

.pagination .page-item.active .page-link {
    background-color: #0d9488;
    border-color: #0d9488;
    color: white;
}

.pagination .page-item.disabled .page-link {
    color: #94a3b8;
    pointer-events: none;
    background-color: #f8fafc;
    border-color: #e2e8f0;
}

/* Responsive pagination */
@media (max-width: 768px) {
    .pagination .page-link {
        padding: 0.35rem 0.7rem;
        font-size: 0.7rem;
    }
    
    .pagination {
        gap: 3px;
    }
}

/* Small pagination text */
.pagination .page-item:first-child .page-link,
.pagination .page-item:last-child .page-link {
    font-weight: 600;
}
</style>

@push('scripts')
<script>
$(document).ready(function() {
    // Row click to view email
    $('.pnl-row').on('click', function(e) {
        if ($(e.target).closest('.action-btn').length) return;
        
        const id = $(this).data('id');
        const modal = new bootstrap.Modal(document.getElementById('pnlEmailModal'));
        const modalBody = document.getElementById('pnlModalBody');
        
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Loading...</p></div>';
        modal.show();
        
        $.ajax({
            url: '/pnl/view-email/' + id,
            method: 'GET',
            success: function(data) {
                if (data.success) {
                    let content = data.email.body_html || data.email.body || '<p>No content</p>';
                    modalBody.innerHTML = '<div class="email-content p-4">' + content + '</div>';
                }
            },
            error: function() {
                modalBody.innerHTML = '<div class="alert alert-danger m-3">Error loading email</div>';
            }
        });
    });
    
    // Update PnL button
   // Update PnL button
$('.update-pnl-btn').on('click', function(e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const btn = $(this);
    
    if (!confirm('Process this email and update PnL Excel?')) return;
    
    btn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
    
    $.ajax({
        url: '{{ route("pnl.update-excel") }}',
        method: 'POST',
        data: { id: id, _token: '{{ csrf_token() }}' },
        success: function(response) {
            // Show different message for update vs insert
            if (response.action === 'updated') {
                toastr.success('✅ PnL Updated! Previous entries were replaced with new data.', {
                    timeOut: 5000
                });
            } else {
                toastr.success(response.message || '✅ New PnL Inserted successfully!', {
                    timeOut: 5000
                });
            }
            
            // Optional: Show item count
            if (response.items_count) {
                toastr.info(`📊 ${response.items_count} items processed`, {
                    timeOut: 3000
                });
            }
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.message || 'Update failed');
        },
        complete: function() {
            btn.html('<i class="fas fa-file-excel"></i>').prop('disabled', false);
        }
    });
});
});
</script>
@endpush
@endsection