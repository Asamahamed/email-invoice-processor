<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Invoice Processing System</title>
    
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
            background-color: #f1f5f9;
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
        }
        
        /* Navbar - Primary Color */
        .navbar {
            background-color: var(--primary) !important;
            border-bottom: 3px solid var(--accent);
        }
        
        .navbar-brand {
            font-weight: 600;
            color: white !important;
        }
        
        .navbar .btn-outline-light {
            border-color: rgba(255,255,255,0.3);
            color: white;
        }
        
        .navbar .btn-outline-light:hover {
            background-color: var(--accent);
            border-color: var(--accent);
        }
        
        /* Cards */
        .card {
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background-color: white;
            border-bottom: 2px solid var(--accent);
            font-weight: 600;
            color: var(--primary);
            padding: 1rem 1.25rem;
        }
        
        /* Stats Cards */
        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.25rem;
            transition: all 0.2s;
        }
        
        .stat-card:hover {
            border-color: var(--accent);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--light-bg);
            color: var(--primary);
        }
        
        .stat-title {
            font-size: 0.8rem;
            font-weight: 500;
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
            background-color: var(--light-bg);
            color: var(--primary);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
            padding: 0.875rem 1rem;
        }
        
        .table tbody td {
            padding: 0.875rem 1rem;
            vertical-align: middle;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--border);
            color: var(--secondary);
        }
        
        .table-hover tbody tr:hover {
            background-color: var(--light-bg);
        }
        
        /* Buttons - Using theme colors */
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            font-weight: 500;
            font-size: 0.8rem;
            padding: 0.4rem 1rem;
            border-radius: 6px;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-accent {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
            font-weight: 500;
            font-size: 0.8rem;
            padding: 0.4rem 1rem;
            border-radius: 6px;
        }
        
        .btn-accent:hover {
            background-color: var(--accent-dark);
            border-color: var(--accent-dark);
            color: white;
        }
        
        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .btn-secondary {
            background-color: var(--secondary);
            border-color: var(--secondary);
            font-weight: 500;
            font-size: 0.8rem;
            padding: 0.4rem 1rem;
            border-radius: 6px;
        }
        
        /* Badges - Using theme colors */
        .badge-credit {
            background-color: #e0f2fe;
            color: var(--primary);
            font-weight: 500;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.7rem;
        }
        
        .badge-non-credit {
            background-color: #fef3c7;
            color: #92400e;
            font-weight: 500;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.7rem;
        }
        
        .badge-pending {
            background-color: var(--light-bg);
            color: var(--secondary);
            font-weight: 500;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.7rem;
        }
        
        .badge-read {
            background-color: #dcfce7;
            color: #166534;
            font-weight: 500;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.7rem;
        }
        
        .badge-unread {
            background-color: #fee2e2;
            color: #991b1b;
            font-weight: 500;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.7rem;
        }
        
        /* Form Controls */
        .form-control, .form-select {
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
            color: var(--primary);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        .form-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }
        
        /* Filter Section */
        .filter-section {
            background-color: var(--light-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        /* Pagination */
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            color: var(--primary);
            border: 1px solid var(--border);
            margin: 0 2px;
            border-radius: 6px;
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        
        .page-link:hover {
            background-color: var(--light-bg);
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
            background-color: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
            border-radius: 8px;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 8px;
        }
        
        .alert-info {
            background-color: #e0f2fe;
            border: 1px solid #bae6fd;
            color: var(--primary);
            border-radius: 8px;
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
        
        /* Code / Reference */
        code {
            color: var(--accent);
            background-color: var(--light-bg);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stat-value {
                font-size: 1.3rem;
            }
            
            .table-responsive {
                font-size: 0.75rem;
            }
            
            .btn {
                padding: 0.3rem 0.7rem;
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="{{ route('index') }}">
                <i class="fas fa-file-invoice me-2"></i>
                Invoice Processing System
            </a>
            <div>
                <a href="{{ route('non-credit') }}" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-file-alt me-1"></i> Non-Credit
                </a>
                <a href="{{ route('credit') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-credit-card me-1"></i> Credit
                </a>
                <a href="{{ route('pnl.index') }}" class="btn btn-info btn-sm">
    📊 PnL Reports
</a>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid px-4 py-4">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        
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
    
    @stack('scripts')
</body>
</html>