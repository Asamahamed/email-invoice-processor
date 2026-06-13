<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Invoice Processing System</title>
    <!-- Toastr CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
        }
        
        /* Theme Colors - Only 3 colors */
        :root {
            --primary: #1e293b;
            --primary-dark: #0f172a;
            --secondary: #64748b;
            --accent: #0d9488;
            --accent-dark: #0f766e;
            --light-bg: #f8fafc;
            --border: #e2e8f0;
            --hover-bg: #f1f5f9;
        }
        
        /* Navbar - Dark Slate */
        .navbar {
            background-color: var(--primary) !important;
            border-bottom: 3px solid var(--accent);
            padding: 0.75rem 0;
        }
        
        .navbar-brand {
            font-weight: 600;
            font-size: 1.1rem;
            color: white !important;
        }
        
        .navbar-brand i {
            color: var(--accent);
        }
        
        .navbar .btn-outline-light {
            border-color: rgba(255,255,255,0.2);
            color: #e2e8f0;
            font-size: 0.8rem;
        }
        
        .navbar .btn-outline-light:hover {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        
        /* Cards */
        .card {
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            background: white;
        }
        
        .card-header {
            background-color: white;
            border-bottom: 2px solid var(--accent);
            font-weight: 600;
            color: var(--primary);
            padding: 1rem 1.25rem;
            border-radius: 12px 12px 0 0;
        }
        
        /* Stats Cards */
        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background-color: var(--accent);
        }
        
        .stat-card:hover {
            border-color: var(--accent);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #e8f4f2;
            color: var(--accent);
        }
        
        .stat-title {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0;
        }
        
        /* Tables */
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: #f8fafc;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
            padding: 0.875rem 1rem;
        }
        
        .table tbody td {
            padding: 0.875rem 1rem;
            vertical-align: middle;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border);
            color: var(--primary-dark);
        }
        
        .table-hover tbody tr:hover {
            background-color: var(--hover-bg);
        }
        
        /* Buttons */
        .btn-primary {
            background-color: var(--accent);
            border-color: var(--accent);
            font-weight: 500;
            font-size: 0.75rem;
            padding: 0.4rem 1rem;
            border-radius: 6px;
        }
        
        .btn-primary:hover {
            background-color: var(--accent-dark);
            border-color: var(--accent-dark);
        }
        
        .btn-accent {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
            font-weight: 500;
            font-size: 0.75rem;
            padding: 0.4rem 1rem;
            border-radius: 6px;
        }
        
        .btn-accent:hover {
            background-color: var(--accent-dark);
            border-color: var(--accent-dark);
            color: white;
        }
        
        .btn-outline-primary {
            color: var(--accent);
            border-color: var(--accent);
            font-size: 0.75rem;
        }
        
        .btn-outline-primary:hover {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        
        .btn-secondary {
            background-color: #e2e8f0;
            border-color: #e2e8f0;
            color: var(--primary);
            font-weight: 500;
            font-size: 0.75rem;
            padding: 0.4rem 1rem;
            border-radius: 6px;
        }
        
        .btn-secondary:hover {
            background-color: #cbd5e1;
            border-color: #cbd5e1;
        }
        
        /* Badges */
        .badge-credit {
            background-color: #e0f2fe;
            color: var(--primary);
            font-weight: 500;
            padding: 0.25rem 0.6rem;
            border-radius: 4px;
            font-size: 0.65rem;
        }
        
        .badge-non-credit {
            background-color: #fef3c7;
            color: #92400e;
            font-weight: 500;
            padding: 0.25rem 0.6rem;
            border-radius: 4px;
            font-size: 0.65rem;
        }
        
        .badge-pending {
            background-color: #f1f5f9;
            color: var(--secondary);
            font-weight: 500;
            padding: 0.25rem 0.6rem;
            border-radius: 4px;
            font-size: 0.65rem;
        }
        
        /* Form Controls */
        .form-control, .form-select {
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.8rem;
            padding: 0.5rem 0.75rem;
            color: var(--primary);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        .form-label {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }
        
        /* Filter Section */
        .filter-section {
            background-color: var(--light-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        /* Pagination */
        .pagination {
            margin-bottom: 0;
            gap: 4px;
        }
        
        .page-link {
            color: var(--primary);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
        }
        
        .page-link:hover {
            background-color: var(--hover-bg);
            border-color: var(--accent);
            color: var(--accent);
        }
        
        .page-item.active .page-link {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        
        /* Alerts */
        .alert-success {
            background-color: #e8f4f2;
            border: 1px solid #c6e9e3;
            color: var(--accent);
            border-radius: 10px;
        }
        
        .alert-danger {
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            color: #991b1b;
            border-radius: 10px;
        }
        
        .alert-info {
            background-color: #e0f2fe;
            border: 1px solid #bae6fd;
            color: #0369a1;
            border-radius: 10px;
        }
        
        /* Loading Spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .spinner-overlay.show {
            display: flex;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
            color: var(--accent);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        code {
            color: var(--accent);
            background-color: var(--light-bg);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
        }
        
        @media (max-width: 768px) {
            .stat-value {
                font-size: 1.3rem;
            }
            
            .table-responsive {
                font-size: 0.7rem;
            }
            
            .btn {
                padding: 0.3rem 0.7rem;
                font-size: 0.65rem;
            }
        }
        /* Toastr Custom Styling */
#toast-container > div {
    opacity: 1;
    padding: 15px;
    font-size: 14px;
    border-radius: 8px;
}

.toast-success {
    background-color: #10b981 !important;
    color: white !important;
}

.toast-error {
    background-color: #ef4444 !important;
    color: white !important;
}

.toast-info {
    background-color: #0d9488 !important;
    color: white !important;
}

.toast-warning {
    background-color: #f59e0b !important;
    color: white !important;
}

.toast-success .toast-title,
.toast-error .toast-title,
.toast-info .toast-title,
.toast-warning .toast-title {
    font-weight: 600;
    margin-bottom: 5px;
}

.toast-success .toast-message,
.toast-error .toast-message,
.toast-info .toast-message,
.toast-warning .toast-message {
    color: white;
}

#toast-container .toast-close-button {
    color: white !important;
    opacity: 0.8;
}

#toast-container .toast-close-button:hover {
    opacity: 1;
}

/* Progress bar colors */
.toast-progress {
    background-color: rgba(255, 255, 255, 0.3);
}
    </style>
</head>
<body>
    <!-- Navbar - Only PnL Reports button -->
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="{{ route('index') }}">
                <i class="fas fa-file-invoice me-2"></i>
                Invoice Processing System
            </a>
            <div>
                <a href="{{ route('pnl.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-chart-line me-1"></i> PnL Reports
                </a>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid px-4 py-4">
        {{-- @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif --}}
        
        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        
        @yield('content')
    </div>
    
    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner-border" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('form').on('submit', function() {
                $('#loadingSpinner').addClass('show');
            });
            
            $(window).on('load', function() {
                $('#loadingSpinner').removeClass('show');
            });
        });
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<script>
    toastr.options = {
        "closeButton": true,
        "progressBar": true,
        "positionClass": "toast-top-right",
        "timeOut": "5000",
        "extendedTimeOut": "1000",
        "showEasing": "swing",
        "hideEasing": "linear",
        "showMethod": "fadeIn",
        "hideMethod": "fadeOut",
        "preventDuplicates": true,
    }
</script>
    @stack('scripts')
</body>
</html>