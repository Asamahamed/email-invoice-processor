@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header bg-success text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h3 class="mb-0">📊 PnL Excel Sheet - {{ $countryName }} ({{ $country }})</h3>
                <div>
                    <a href="{{ route('pnl.index') }}" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left"></i> Back to PnL List
                    </a>
                    <a href="{{ route('pnl.export') }}" class="btn btn-warning btn-sm">
                        <i class="fas fa-download"></i> Download CSV
                    </a>
                </div>
            </div>
        </div>
        <div class="card-body">
            {!! $html !!}
        </div>
        {{-- <div class="card-footer text-muted">
            <i class="fas fa-info-circle"></i> 
            <strong>Note:</strong> Each attraction, transfer, and hotel gets its own row. 
            Amounts are converted from USD to local currency using today's exchange rate (1 USD = 1.35 {{ $country == 'SG' ? 'SGD' : ($country == 'MY' ? 'MYR' : ($country == 'VN' ? 'VND' : 'LKR')) }}).
        </div> --}}
    </div>
</div>

<style>
    .table-responsive {
        overflow-x: auto;
    }
    .table th, .table td {
        white-space: nowrap;
        padding: 8px;
    }
    .table td {
        max-width: 300px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(0,0,0,.05);
    }
</style>
@endsection