@extends('layouts.app')

@section('content')
<div class="selected-view-container">
    <!-- Header -->
    <div class="selected-header mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="selected-title">
                    <i class="fas fa-eye me-2"></i> Selected PnL Records
                </h1>
                <p class="selected-subtitle">
                    Showing {{ $records->count() }} selected record(s)
                </p>
            </div>
            <div>
                <a href="{{ route('pnl.index') }}" class="btn-excel btn-excel-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
              <a href="{{ route('pnl.export.selected', ['ids' => $records->pluck('id')->implode(',')]) }}" 
   class="btn-excel btn-excel-success ms-2">
    <i class="fas fa-download me-1"></i> Export All
</a>
            </div>
        </div>
    </div>

    <!-- Selected Records -->
    <div class="selected-records">
        {!! $html !!}
    </div>
</div>

<style>
    .selected-view-container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 20px;
    }

    .selected-header {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-radius: 20px;
        padding: 1.75rem 2rem;
        color: white;
    }

    .selected-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 0 0.25rem 0;
    }

    .selected-subtitle {
        font-size: 0.85rem;
        opacity: 0.8;
        margin: 0;
    }

    .record-section {
        background: white;
        border-radius: 20px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .record-header {
        padding: 1.25rem 1.5rem;
        background: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .record-title {
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0;
        color: #1e293b;
    }

    .record-meta {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .record-meta .badge {
        font-size: 0.75rem;
        padding: 0.4rem 0.8rem;
    }

    .btn-excel {
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

    .btn-excel-secondary {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .btn-excel-secondary:hover {
        background: rgba(255, 255, 255, 0.3);
        color: white;
        transform: translateY(-1px);
    }

    .btn-excel-success {
        background-color: white;
        color: #059669;
    }

    .btn-excel-success:hover {
        background-color: #f8fafc;
        transform: translateY(-1px);
        color: #047857;
    }

    @media (max-width: 768px) {
        .record-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .selected-header .d-flex {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 1rem;
        }
    }
</style>
@endsection