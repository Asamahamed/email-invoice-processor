@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {!! session('success') !!}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            
            @if(session('error'))
                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>
            @endif
            
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="mb-0">📧 Manual Email Entry</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        <strong>Note:</strong> Since IMAP is not configured, use this form to manually add emails.
                        <br>Select <strong>"Yes" for Handling Fee</strong> to generate a NON-CREDIT invoice.
                    </p>
                    
                    <form method="POST" action="{{ route('manual.email.store') }}">
                        @csrf
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">From Email *</label>
                                <input type="email" name="from_email" class="form-control @error('from_email') is-invalid @enderror" 
                                       value="{{ old('from_email', 'supplier@sharmilatravels.com') }}" required>
                                @error('from_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subject *</label>
                                <input type="text" name="subject" class="form-control @error('subject') is-invalid @enderror" 
                                       value="{{ old('subject', 'Invoice IS47778 - Payment Confirmation') }}" required>
                                @error('subject') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reference Number</label>
                                <input type="text" name="reference_no" class="form-control" 
                                       value="{{ old('reference_no', '450877CNTL') }}"
                                       placeholder="e.g., 450877CNTL">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name</label>
                                <input type="text" name="customer_name" class="form-control" 
                                       value="{{ old('customer_name', 'Pick Your Trail') }}"
                                       placeholder="e.g., Pick Your Trail">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Has Handling Fee?</label>
                                <select name="has_handling_fee" class="form-control" id="has_fee">
                                    <option value="0">No (CREDIT - No invoice)</option>
                                    <option value="1" selected>Yes (NON-CREDIT - Generate invoice)</option>
                                </select>
                                <small class="text-muted">If Yes, an invoice will be automatically generated</small>
                            </div>
                            
                            <div class="col-md-6 mb-3" id="fee_div">
                                <label class="form-label">Handling Fee Amount</label>
                                <input type="number" name="handling_fee_amount" class="form-control" step="0.01"
                                       value="{{ old('handling_fee_amount', '474.55') }}"
                                       placeholder="e.g., 474.55">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Total Amount</label>
                            <input type="number" name="total_amount" class="form-control" step="0.01"
                                   value="{{ old('total_amount', '72416.32') }}"
                                   placeholder="e.g., 72416.32">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email Body (Optional)</label>
                            <textarea name="body" rows="4" class="form-control" 
                                      placeholder="Paste email content here...">Your Ref: 450877CNTL
Handling Fee: INR 474.55
TOTAL: INR 72,416.32
Travel Dates: 31/05/2026 - 06/06/2026</textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Process Email
                            </button>
                            <a href="{{ route('invoices.index') }}" class="btn btn-secondary">
                                View All Records
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Sample Data Section -->
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">📋 Quick Test Samples</h4>
                </div>
                <div class="card-body">
                    <p>Click buttons below to test different scenarios:</p>
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <button class="btn btn-outline-warning w-100" onclick="fillNonCredit()">
                                🟡 NON-CREDIT Sample (With Handling Fee)
                            </button>
                        </div>
                        <div class="col-md-6 mb-2">
                            <button class="btn btn-outline-success w-100" onclick="fillCredit()">
                                🟢 CREDIT Sample (No Handling Fee)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('has_fee').addEventListener('change', function() {
    var div = document.getElementById('fee_div');
    div.style.display = this.value == 1 ? 'block' : 'none';
});

function fillNonCredit() {
    document.querySelector('[name="from_email"]').value = 'supplier@sharmilatravels.com';
    document.querySelector('[name="subject"]').value = 'Invoice IS47778 - Payment Required';
    document.querySelector('[name="reference_no"]').value = '450877CNTL';
    document.querySelector('[name="customer_name"]').value = 'Pick Your Trail';
    document.querySelector('[name="has_handling_fee"]').value = '1';
    document.getElementById('has_fee').dispatchEvent(new Event('change'));
    document.querySelector('[name="handling_fee_amount"]').value = '474.55';
    document.querySelector('[name="total_amount"]').value = '72416.32';
    document.querySelector('[name="body"]').value = 'Your Ref: 450877CNTL\nHandling Fee: INR 474.55\nTOTAL: INR 72,416.32\nTravel Dates: 31/05/2026 - 06/06/2026';
}

function fillCredit() {
    document.querySelector('[name="from_email"]').value = 'supplier@appleholidays.com';
    document.querySelector('[name="subject"]').value = 'Invoice IS48151 - Booking Confirmation';
    document.querySelector('[name="reference_no"]').value = '462582CNTL';
    document.querySelector('[name="customer_name"]').value = 'MAKE MY TRIP INDIA PVT LTD';
    document.querySelector('[name="has_handling_fee"]').value = '0';
    document.getElementById('has_fee').dispatchEvent(new Event('change'));
    document.querySelector('[name="handling_fee_amount"]').value = '0';
    document.querySelector('[name="total_amount"]').value = '441.00';
    document.querySelector('[name="body"]').value = 'Your Ref: 462582CNTL\nTotal tour cost: USD 441.00\nTravel Dates: 31/05/2026 - 07/06/2026';
}
</script>
@endsection