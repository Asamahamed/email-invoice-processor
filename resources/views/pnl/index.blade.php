{{-- resources/views/pnl/index.blade.php --}}
@extends('layouts.app')

@section('content')
    <div class="pnl-container">
        <!-- Header Section -->
        <div class="pnl-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div>
                        <h1 class="pnl-title">Profit & Loss Dashboard</h1>
                        <p class="pnl-subtitle">Manage vendor expenses and financial overview</p>
                    </div>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn-pnl btn-pnl-primary"
                        onclick="event.preventDefault(); document.getElementById('fetch-form').submit();">
                        <i class="fas fa-cloud-download-alt me-2"></i> Fetch Emails
                    </button>
                    <form id="fetch-form" action="{{ route('pnl.fetch') }}" method="POST" class="d-none">
                        @csrf
                    </form>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card stat-card-primary">
                <div class="stat-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Total Records</span>
                    <h3 class="stat-value">{{ number_format($stats['total'] ?? 0) }}</h3>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-arrow-up"></i>
                </div>
            </div>
            <div class="stat-card stat-card-success">
                <div class="stat-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Total Amount</span>
                    <h3 class="stat-value">${{ number_format($stats['total_amount'] ?? 0, 2) }}</h3>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            <div class="stat-card stat-card-warning">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Pending</span>
                    <h3 class="stat-value">{{ number_format($stats['pending'] ?? 0) }}</h3>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-hourglass-half"></i>
                </div>
            </div>
            <div class="stat-card stat-card-info">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Approved</span>
                    <h3 class="stat-value">{{ number_format($stats['approved'] ?? 0) }}</h3>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-check-double"></i>
                </div>
            </div>
        </div>

        <!-- Filter & Actions Section -->
        <div class="filter-section">
            <div class="filter-card">
                <div class="filter-header">
                    <div class="filter-header-left">
                        <i class="fas fa-sliders-h me-2"></i>
                        <span>Filters</span>
                    </div>
                    <div class="filter-header-right">
                        <span class="record-badge">
                            <i class="fas fa-database me-1"></i>
                            {{ $pnlRecords->total() }} Records
                        </span>
                    </div>
                </div>
                <div class="filter-body">
                    <form method="GET" class="filter-form">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label><i class="fas fa-search"></i> Search</label>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control form-control-pnl"
                                        placeholder="Vendor, Invoice, Subject..." value="{{ request('search') }}">
                                </div>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-tag"></i> Category</label>
                                <select name="category" class="form-select form-select-pnl">
                                    <option value="">All Categories</option>
                                    @foreach ($categories ?? [] as $cat)
                                        <option value="{{ $cat }}"
                                            {{ request('category') == $cat ? 'selected' : '' }}>
                                            {{ $cat }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-globe"></i> Country</label>
                                <select name="country" class="form-select form-select-pnl">
                                    <option value="">All Countries</option>
                                    @foreach ($countries ?? [] as $code => $name)
                                        <option value="{{ $code }}"
                                            {{ request('country') == $code ? 'selected' : '' }}>
                                            {{ $name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="filter-row">
                            <div class="filter-group">
                                <label><i class="fas fa-envelope"></i> Read Status</label>
                                <select name="read_filter" class="form-select form-select-pnl">
                                    <option value="">All</option>
                                    <option value="read" {{ request('read_filter') == 'read' ? 'selected' : '' }}>Read
                                    </option>
                                    <option value="unread" {{ request('read_filter') == 'unread' ? 'selected' : '' }}>
                                        Unread</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-check-circle"></i> Approval Status</label>
                                <select name="status" class="form-select form-select-pnl">
                                    <option value="">All</option>
                                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending
                                    </option>
                                    <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>
                                        Approved</option>
                                    <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>
                                        Rejected</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date From</label>
                                <input type="date" name="date_from" class="form-control form-control-pnl"
                                    value="{{ request('date_from') }}">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date To</label>
                                <input type="date" name="date_to" class="form-control form-control-pnl"
                                    value="{{ request('date_to') }}">
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-pnl btn-pnl-primary">
                                <i class="fas fa-search me-2"></i> Apply Filters
                            </button>
                            <a href="{{ route('pnl.index') }}" class="btn-pnl btn-pnl-secondary">
                                <i class="fas fa-undo-alt me-2"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions">
            <div class="bulk-actions-left">
                <span class="bulk-label">
                    <i class="fas fa-check-square me-2"></i>
                    Select records to perform bulk actions
                </span>
            </div>
            <div class="bulk-actions-right">
                <button type="button" id="viewSelectedBtn" class="btn-pnl btn-pnl-info" disabled>
                    <i class="fas fa-eye me-2"></i> View Selected (<span id="viewSelectedCount">0</span>)
                </button>
                <button type="button" id="downloadSelectedBtn" class="btn-pnl btn-pnl-success" disabled>
                    <i class="fas fa-download me-2"></i> Download Selected (<span id="selectedCount">0</span>)
                </button>
                <div class="dropdown">
                    <button class="btn-pnl btn-pnl-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-file-export me-2"></i> Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('pnl.export') }}">
                                <i class="fas fa-globe me-2"></i> Export All Countries
                            </a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="{{ route('pnl.export-country', 'VN') }}">
                                <span class="country-flag">🇻🇳</span> Vietnam
                            </a></li>
                        <li><a class="dropdown-item" href="{{ route('pnl.export-country', 'LK') }}">
                                <span class="country-flag">🇱🇰</span> Sri Lanka
                            </a></li>
                        <li><a class="dropdown-item" href="{{ route('pnl.export-country', 'SG') }}">
                                <span class="country-flag">🇸🇬</span> Singapore
                            </a></li>
                        <li><a class="dropdown-item" href="{{ route('pnl.export-country', 'MY') }}">
                                <span class="country-flag">🇲🇾</span> Malaysia
                            </a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="pnl-table">
                    <thead>
                        <tr>
                            <th class="checkbox-col">
                                <div class="custom-checkbox">
                                    <input type="checkbox" id="selectAllCheckbox">
                                    <label for="selectAllCheckbox"></label>
                                </div>
                            </th>
                            <th>#</th>
                            <th>Date</th>
                            <th>From</th>
                            <th>Guest Name</th>
                            <th>Subject</th>
                            <th>Tour Ref</th>
                            <th>Invoice #</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pnlRecords as $record)
                            <tr class="pnl-row" data-id="{{ $record->id }}">
                                <td class="checkbox-col" onclick="event.stopPropagation()">
                                    <div class="custom-checkbox">
                                        <input type="checkbox" class="record-checkbox" id="record_{{ $record->id }}"
                                            value="{{ $record->id }}">
                                        <label for="record_{{ $record->id }}"></label>
                                    </div>
                                </td>
                                <td>
                                    <span class="row-number">
                                        {{ $loop->iteration }}
                                    </span>
                                </td>
                                <td>
                                <td>
                                    <div class="date-cell">
                                        <span class="date-main">{{ $record->received_at->format('d/m/Y') }}</span>
                                        <span class="date-time">{{ $record->received_at->format('H:i') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="from-cell">
                                        <span class="from-name">{{ $record->from_address ?: '-' }}</span>
                                        <span class="from-email">{{ $record->from_email }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="vendor-cell">
                                        <span class="vendor-name">{{ $record->vendor_name ?: '-' }}</span>
                                        @if ($record->country_code)
                                            <span class="country-tag">{{ $record->country_code }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="subject-cell" title="{{ $record->subject }}">
                                        {{ Str::limit($record->subject, 35) }}
                                    </div>
                                </td>
                                <td><code class="ref-code">{{ $record->tour_ref ?: '-' }}</code></td>
                                <td><code class="invoice-code">{{ $record->invoice_number ?: '-' }}</code></td>
                                <td>
                                    @if ($record->category == 'Multi')
                                        <span class="badge-category badge-multi">Multiple</span>
                                    @elseif($record->category)
                                        <span class="badge-category badge-default">{{ $record->category }}</span>
                                    @else
                                        <span class="badge-category badge-other">Other</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="amount-value">${{ number_format($record->amount, 2) }}</span>
                                </td>
                                <td>
                                    @if ($record->read_status == 'unread')
                                        <span class="status-badge status-unread">
                                            <i class="fas fa-circle me-1"></i> Unread
                                        </span>
                                    @else
                                        <span class="status-badge status-read">
                                            <i class="fas fa-check-circle me-1"></i> Read
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="{{ route('pnl.view-excel', ['country' => $record->country_code ?? 'SG', 'id' => $record->id]) }}"
                                            class="action-btn action-btn-view" target="_blank" title="View PnL Sheet">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="empty-state">
                                    <div class="empty-state-content">
                                        <i class="fas fa-inbox"></i>
                                        <h4>No PnL Records Found</h4>
                                        <p>Click the "Fetch Emails" button to import PnL data from emails.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <!-- Pagination -->
            @if ($pnlRecords->hasPages())
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Showing {{ $pnlRecords->firstItem() ?? 0 }} to {{ $pnlRecords->lastItem() ?? 0 }} of
                        {{ $pnlRecords->total() }} results
                    </div>
                    <div class="pagination-container">
                        {{ $pnlRecords->links('pagination::bootstrap-5') }}
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Email Modal -->
    <div class="modal fade" id="pnlEmailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-envelope me-2"></i>Email Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" id="pnlModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading email content...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* ============================================
                   PnL Dashboard - Professional UI
                   ============================================ */

        /* Container */
        .pnl-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 0 0 2rem;
        }

        /* ============================================
                   HEADER
                   ============================================ */
        .pnl-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            border-radius: 16px;
            padding: 1.75rem 2rem;
            margin-bottom: 1.75rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.15);
        }

        .pnl-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(13, 148, 136, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .header-icon {
            width: 56px;
            height: 56px;
            background: rgba(13, 148, 136, 0.15);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #0d9488;
            border: 1px solid rgba(13, 148, 136, 0.2);
        }

        .pnl-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0 0 0.1rem 0;
            letter-spacing: -0.02em;
        }

        .pnl-subtitle {
            font-size: 0.85rem;
            color: rgba(248, 250, 252, 0.6);
            margin: 0;
        }

        .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* ============================================
                   BUTTONS
                   ============================================ */
        .btn-pnl {
            display: inline-flex;
            align-items: center;
            padding: 0.6rem 1.25rem;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.25s ease;
            border: none;
            cursor: pointer;
            letter-spacing: 0.01em;
            gap: 0.25rem;
        }

        .btn-pnl-primary {
            background: #0d9488;
            color: #fff;
        }

        .btn-pnl-primary:hover {
            background: #0f766e;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.35);
            color: #fff;
        }

        .btn-pnl-success {
            background: #10b981;
            color: #fff;
        }

        .btn-pnl-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35);
            color: #fff;
        }

        .btn-pnl-info {
            background: #3b82f6;
            color: #fff;
        }

        .btn-pnl-info:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
            color: #fff;
        }

        .btn-pnl-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .btn-pnl-secondary:hover {
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            transform: translateY(-2px);
        }

        .btn-pnl:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-pnl .dropdown-toggle::after {
            margin-left: 0.5rem;
        }

        /* ============================================
                   STATS CARDS
                   ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid #e8edf2;
            transition: all 0.25s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            border-color: #d1d9e6;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .stat-card-primary .stat-icon {
            background: rgba(13, 148, 136, 0.12);
            color: #0d9488;
        }

        .stat-card-success .stat-icon {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
        }

        .stat-card-warning .stat-icon {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
        }

        .stat-card-info .stat-icon {
            background: rgba(59, 130, 246, 0.12);
            color: #3b82f6;
        }

        .stat-info {
            flex: 1;
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            display: block;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            line-height: 1.3;
        }

        .stat-trend {
            font-size: 0.85rem;
            color: #94a3b8;
            opacity: 0.5;
        }

        /* ============================================
                   FILTER SECTION
                   ============================================ */
        .filter-section {
            margin-bottom: 1.25rem;
        }

        .filter-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e8edf2;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        .filter-header {
            padding: 0.9rem 1.5rem;
            background: #fafbfc;
            border-bottom: 1px solid #e8edf2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-header-left {
            font-size: 0.8rem;
            font-weight: 600;
            color: #1e293b;
        }

        .filter-header-left i {
            color: #94a3b8;
        }

        .record-badge {
            font-size: 0.7rem;
            font-weight: 500;
            color: #64748b;
            background: #f1f5f9;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
        }

        .filter-body {
            padding: 1.25rem 1.5rem;
        }

        .filter-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .filter-group label {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .filter-group label i {
            margin-right: 0.3rem;
            font-size: 0.6rem;
            color: #cbd5e1;
        }

        .form-control-pnl,
        .form-select-pnl {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.45rem 0.75rem;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            background: #fff;
            color: #1e293b;
            height: 38px;
        }

        .form-control-pnl:focus,
        .form-select-pnl:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
            outline: none;
        }

        .input-group .form-control-pnl {
            border-radius: 8px 0 0 8px;
        }

        .input-group .input-group-text {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-right: none;
            border-radius: 8px 0 0 8px;
            color: #94a3b8;
            font-size: 0.75rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            padding-top: 0.5rem;
            border-top: 1px solid #f1f5f9;
        }

        /* ============================================
                   BULK ACTIONS
                   ============================================ */
        .bulk-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding: 0.75rem 1.25rem;
            background: #fafbfc;
            border-radius: 12px;
            margin-bottom: 1.25rem;
            border: 1px solid #e8edf2;
        }

        .bulk-actions-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-label {
            font-size: 0.75rem;
            color: #64748b;
        }

        .bulk-actions-right {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .country-flag {
            margin-right: 0.5rem;
        }

        /* ============================================
                   TABLE
                   ============================================ */
        .table-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e8edf2;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        .pnl-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .pnl-table thead th {
            padding: 0.75rem 1rem;
            background: #fafbfc;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            border-bottom: 1px solid #e8edf2;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .pnl-table tbody td {
            padding: 0.75rem 1rem;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .pnl-row {
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .pnl-row:hover {
            background-color: #f8fafc;
        }

        .pnl-row:last-child td {
            border-bottom: none;
        }

        /* Checkbox */
        .custom-checkbox {
            position: relative;
            display: inline-block;
        }

        .custom-checkbox input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .custom-checkbox label {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid #cbd5e1;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .custom-checkbox input:checked+label {
            background: #0d9488;
            border-color: #0d9488;
        }

        .custom-checkbox input:checked+label::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
        }

        .custom-checkbox input:hover+label {
            border-color: #0d9488;
        }

        .checkbox-col {
            width: 44px;
            text-align: center;
        }

        /* Row Number */
        .row-number {
            font-weight: 500;
            color: #94a3b8;
            font-size: 0.75rem;
        }

        /* Date Cell */
        .date-cell {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }

        .date-main {
            font-weight: 500;
            color: #1e293b;
        }

        .date-time {
            font-size: 0.65rem;
            color: #94a3b8;
        }

        /* From Cell */
        .from-cell {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }

        .from-name {
            font-weight: 500;
            color: #1e293b;
        }

        .from-email {
            font-size: 0.65rem;
            color: #94a3b8;
        }

        /* Vendor Cell */
        .vendor-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .vendor-name {
            font-weight: 500;
            color: #1e293b;
        }

        .country-tag {
            font-size: 0.55rem;
            font-weight: 600;
            padding: 0.1rem 0.5rem;
            background: #e8edf2;
            border-radius: 4px;
            color: #64748b;
            text-transform: uppercase;
        }

        /* Subject */
        .subject-cell {
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #475569;
        }

        /* Codes */
        .ref-code,
        .invoice-code {
            background: #f1f5f9;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 500;
            color: #475569;
            font-family: 'Courier New', monospace;
        }

        /* Badges */
        .badge-category {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.6rem;
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

        /* Amount */
        .amount-value {
            font-weight: 600;
            color: #0f172a;
        }

        /* Status */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.6rem;
            font-weight: 500;
        }

        .status-unread {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-unread i {
            font-size: 0.4rem;
            color: #ef4444;
        }

        .status-read {
            background: #d1fae5;
            color: #065f46;
        }

        .status-read i {
            font-size: 0.4rem;
            color: #10b981;
        }

        /* Actions */
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 0.4rem;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .action-btn-view {
            background: #e0f2fe;
            color: #0284c7;
        }

        .action-btn-view:hover {
            background: #0ea5e9;
            color: #fff;
            transform: scale(1.05);
        }

        /* ============================================
                   PAGINATION
                   ============================================ */
        .pagination-wrapper {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e8edf2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .pagination-info {
            font-size: 0.8rem;
            color: #64748b;
        }

        .pagination {
            display: flex;
            gap: 4px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .pagination .page-item {
            display: inline-block;
        }

        .pagination .page-link {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: #1e293b;
            background: #fff;
            border: 1px solid #e8edf2;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .pagination .page-link:hover {
            background: #f1f5f9;
            border-color: #0d9488;
            color: #0d9488;
        }

        .pagination .page-item.active .page-link {
            background: #0d9488;
            border-color: #0d9488;
            color: #fff;
        }

        .pagination .page-item.disabled .page-link {
            color: #cbd5e1;
            pointer-events: none;
            background: #f8fafc;
        }

        /* ============================================
                   EMPTY STATE
                   ============================================ */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem !important;
        }

        .empty-state-content i {
            font-size: 3rem;
            color: #cbd5e1;
            display: block;
            margin-bottom: 1rem;
        }

        .empty-state-content h4 {
            font-size: 1.1rem;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .empty-state-content p {
            color: #94a3b8;
            font-size: 0.85rem;
            margin: 0;
        }

        /* ============================================
                   MODAL
                   ============================================ */
        .modal-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: #fff;
            border: none;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.6;
        }

        .modal-header .btn-close:hover {
            opacity: 1;
        }

        .modal-body .email-content {
            padding: 1.5rem;
        }

        /* ============================================
                   RESPONSIVE
                   ============================================ */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .filter-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .pnl-header {
                padding: 1.25rem;
                border-radius: 12px;
            }

            .header-left {
                width: 100%;
            }

            .header-icon {
                width: 44px;
                height: 44px;
                font-size: 1.1rem;
            }

            .pnl-title {
                font-size: 1.2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .stat-value {
                font-size: 1.2rem;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .filter-body {
                padding: 1rem;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: flex-start;
                padding: 0.75rem 1rem;
            }

            .bulk-actions-right {
                width: 100%;
                flex-wrap: wrap;
            }

            .bulk-actions-right .btn-pnl {
                flex: 1;
                justify-content: center;
                font-size: 0.7rem;
                padding: 0.4rem 0.75rem;
            }

            .pnl-table thead {
                display: none;
            }

            .pnl-table tbody tr {
                display: block;
                margin-bottom: 0.75rem;
                border: 1px solid #e8edf2;
                border-radius: 10px;
                padding: 0.75rem;
                background: #fff;
            }

            .pnl-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.35rem 0;
                border: none;
                font-size: 0.75rem;
            }

            .pnl-table tbody td:before {
                content: attr(data-label);
                font-weight: 600;
                font-size: 0.65rem;
                color: #94a3b8;
                margin-right: 1rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            .pnl-table tbody td:last-child {
                border-bottom: none;
            }

            .action-buttons {
                justify-content: flex-end;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
                padding: 0.75rem 1rem;
            }

            .pagination-info {
                font-size: 0.7rem;
            }

            .pagination .page-link {
                padding: 0.3rem 0.6rem;
                font-size: 0.65rem;
            }

            .custom-checkbox label {
                width: 20px;
                height: 20px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem;
            }

            .stat-card {
                padding: 0.75rem;
                border-radius: 10px;
            }

            .stat-icon {
                width: 34px;
                height: 34px;
                font-size: 0.85rem;
                border-radius: 8px;
            }

            .stat-value {
                font-size: 1rem;
            }

            .stat-label {
                font-size: 0.55rem;
            }

            .pnl-header {
                padding: 0.75rem 1rem;
            }
        }

        /* ============================================
                   DROPDOWN
                   ============================================ */
        .dropdown-menu {
            border: 1px solid #e8edf2;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            padding: 0.5rem;
        }

        .dropdown-item {
            font-size: 0.8rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            color: #1e293b;
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background: #f1f5f9;
            color: #0d9488;
        }

        .dropdown-divider {
            border-color: #e8edf2;
        }

        /* ============================================
                   SCROLLBAR
                   ============================================ */
        .table-responsive::-webkit-scrollbar {
            height: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* ============================================
           PAGINATION
           ============================================ */
        .pagination-wrapper {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e8edf2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .pagination-info {
            font-size: 0.8rem;
            color: #64748b;
        }

        .pagination-container {
            display: flex;
            align-items: center;
        }

        .pagination-container .pagination {
            display: flex;
            gap: 4px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .pagination-container .pagination .page-item {
            display: inline-block;
        }

        .pagination-container .pagination .page-link {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: #1e293b;
            background: #fff;
            border: 1px solid #e8edf2;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .pagination-container .pagination .page-link:hover {
            background: #f1f5f9;
            border-color: #0d9488;
            color: #0d9488;
        }

        .pagination-container .pagination .page-item.active .page-link {
            background: #0d9488;
            border-color: #0d9488;
            color: #fff;
        }

        .pagination-container .pagination .page-item.disabled .page-link {
            color: #cbd5e1;
            pointer-events: none;
            background: #f8fafc;
        }

        .pagination-container .pagination .page-item .page-link {
            cursor: pointer;
        }

        .pagination-container .pagination .page-item.disabled .page-link {
            cursor: not-allowed;
        }

        /* Previous / Next buttons */
        .pagination-container .pagination .page-item:first-child .page-link,
        .pagination-container .pagination .page-item:last-child .page-link {
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
                padding: 0.75rem 1rem;
            }

            .pagination-info {
                font-size: 0.7rem;
            }

            .pagination-container .pagination .page-link {
                padding: 0.3rem 0.6rem;
                font-size: 0.65rem;
            }
        }

        /* Hide the "Showing X to Y of Z results" text from pagination */
        .pagination-wrapper .small.text-muted {
            display: none !important;
        }

        /* Or target the parent div */
        .pagination-wrapper .d-none.flex-sm-fill.d-sm-flex.align-items-sm-center.justify-content-sm-between>div:first-child {
            display: none !important;
        }

        /* Center the pagination when the text is hidden */
        .pagination-wrapper .d-none.flex-sm-fill.d-sm-flex.align-items-sm-center.justify-content-sm-between {
            justify-content: center !important;
        }

        /* Or use this simpler approach */
        .pagination-wrapper p.small.text-muted {
            display: none !important;
        }
    </style>

    @push('scripts')
        <script>
            $(document).ready(function() {
                // ========== SELECT ALL ==========
                $('#selectAllCheckbox').on('change', function() {
                    const isChecked = $(this).prop('checked');
                    $('.record-checkbox').prop('checked', isChecked);
                    updateButtons();
                });

                // ========== INDIVIDUAL CHECKBOX ==========
                $(document).on('change', '.record-checkbox', function() {
                    updateButtons();
                    const total = $('.record-checkbox').length;
                    const checked = $('.record-checkbox:checked').length;
                    $('#selectAllCheckbox').prop('checked', total === checked && total > 0);
                });

                // ========== UPDATE ALL BUTTONS ==========
                function updateButtons() {
                    const selectedIds = getSelectedIds();
                    const count = selectedIds.length;

                    $('#selectedCount').text(count);
                    $('#viewSelectedCount').text(count);
                    $('#downloadSelectedBtn').prop('disabled', count === 0);
                    $('#viewSelectedBtn').prop('disabled', count === 0);
                }

                // ========== GET SELECTED IDs ==========
                function getSelectedIds() {
                    const ids = [];
                    $('.record-checkbox:checked').each(function() {
                        ids.push($(this).val());
                    });
                    return ids;
                }

                // ========== VIEW SELECTED ==========
                $('#viewSelectedBtn').on('click', function() {
                    const selectedIds = getSelectedIds();
                    if (selectedIds.length === 0) {
                        toastr.warning('Please select at least one record to view');
                        return;
                    }

                    const btn = $(this);
                    const originalHtml = btn.html();
                    btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Loading...').prop('disabled', true);

                    const url = '{{ route('pnl.view-selected') }}?ids=' + selectedIds.join(',');
                    window.open(url, '_blank');

                    btn.html(originalHtml).prop('disabled', false);
                });

                // ========== DOWNLOAD SELECTED ==========
                $('#downloadSelectedBtn').on('click', function() {
                    const selectedIds = getSelectedIds();
                    if (selectedIds.length === 0) {
                        toastr.warning('Please select at least one record to download');
                        return;
                    }

                    const btn = $(this);
                    const originalHtml = btn.html();
                    btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Processing...').prop('disabled',
                        true);

                    const params = new URLSearchParams(window.location.search);

                    $.ajax({
                        url: '{{ route('pnl.export.selected') }}',
                        method: 'POST',
                        data: {
                            ids: selectedIds,
                            search: params.get('search') || '',
                            category: params.get('category') || '',
                            country: params.get('country') || '',
                            status: params.get('status') || '',
                            date_from: params.get('date_from') || '',
                            date_to: params.get('date_to') || '',
                            _token: '{{ csrf_token() }}'
                        },
                        xhrFields: {
                            responseType: 'blob'
                        },
                        success: function(response, status, xhr) {
                            let filename = 'selected_pnl_records.xlsx';
                            const contentDisposition = xhr.getResponseHeader('Content-Disposition');
                            if (contentDisposition) {
                                const match = contentDisposition.match(
                                    /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                                if (match && match[1]) {
                                    filename = match[1].replace(/['"]/g, '');
                                }
                            }

                            const url = window.URL.createObjectURL(new Blob([response]));
                            const link = document.createElement('a');
                            link.href = url;
                            link.setAttribute('download', filename);
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            window.URL.revokeObjectURL(url);

                            toastr.success(
                                `✅ Downloaded ${selectedIds.length} record(s) successfully!`);
                        },
                        error: function(xhr) {
                            let errorMsg = 'Download failed';
                            try {
                                const response = JSON.parse(xhr.responseText);
                                errorMsg = response.message || errorMsg;
                            } catch (e) {}
                            toastr.error(errorMsg);
                        },
                        complete: function() {
                            btn.html(originalHtml).prop('disabled', false);
                        }
                    });
                });

                // ========== ROW CLICK TO VIEW EMAIL ==========
                $('.pnl-row').on('click', function(e) {
                    if ($(e.target).closest('.action-btn').length) return;
                    if ($(e.target).closest('.checkbox-col').length) return;

                    const id = $(this).data('id');
                    const modal = new bootstrap.Modal(document.getElementById('pnlEmailModal'));
                    const modalBody = document.getElementById('pnlModalBody');

                    modalBody.innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading email content...</p>
                        </div>
                    `;
                    modal.show();

                    $.ajax({
                        url: '/pnl/view-email/' + id,
                        method: 'GET',
                        success: function(data) {
                            if (data.success) {
                                let content = data.email.body_html || data.email.body ||
                                    '<p class="text-muted p-4">No content available</p>';
                                modalBody.innerHTML = '<div class="email-content p-4">' + content +
                                    '</div>';
                            }
                        },
                        error: function() {
                            modalBody.innerHTML =
                                '<div class="alert alert-danger m-4">Error loading email content</div>';
                        }
                    });
                });
            });
        </script>
    @endpush
@endsection
