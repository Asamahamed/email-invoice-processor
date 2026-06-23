<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Email</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.4;
            color: #333;
            max-width: 650px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #1a237e;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        .header h2 {
            color: #1a237e;
            margin: 0;
            font-size: 18px;
        }
        .header p {
            margin: 3px 0;
            color: #555;
            font-size: 12px;
        }
        .content {
            white-space: pre-wrap;
            font-size: 14px;
        }
        .invoice-details {
            background: #f5f5f5;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 10px 0 15px 0;
            border-left: 3px solid #1a237e;
        }
        .invoice-details table {
            width: 100%;
            border-collapse: collapse;
        }
        .invoice-details td {
            padding: 3px 8px;
            border-bottom: none;
        }
        .invoice-details .label {
            font-weight: bold;
            color: #1a237e;
            width: 35%;
            font-size: 13px;
        }
        .invoice-details .value {
            color: #333;
            width: 65%;
            font-size: 13px;
        }
        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 11px;
            text-align: center;
        }
    </style>
</head>
<body>

    <div class="header">
        <h2>Apple Holidays</h2>
        <p>#2207 - #2208, One Galle Face Tower, 1A Center Road, Colombo 02, Sri Lanka</p>
        <p>Tel: +94-11-2353400 | email: accounts@aahaas.com</p>
    </div>

    <?php
        // Split the body into parts
        $bodyText = $body;
        $keyword = "Kindly find the attached invoice.";
        $pos = strpos($bodyText, $keyword);
        
        if ($pos !== false) {
            $before = substr($bodyText, 0, $pos);
            $after = substr($bodyText, $pos);
        } else {
            // Fallback: put all in before
            $before = $bodyText;
            $after = '';
        }
    ?>

    <!-- ✅ First Part: "Dear Team, ... Greetings from Apple Holidays!" -->
    <div class="content">
        {!! nl2br(e($before)) !!}
    </div>

    <!-- ✅ Invoice Details Section - Display in correct currency -->
    <div class="invoice-details">
        <table>
            <tr>
                <td class="label">Invoice Number</td>
                <td class="value"><strong>{{ $invoice->invoice_number }}</strong></td>
            </tr>
            <tr>
                <td class="label">Agent</td>
                <td class="value">{{ $invoice->customer_name }}</td>
            </tr>
            <tr>
                <td class="label">Tour Ref</td>
                <td class="value">{{ $invoice->tour_ref }}</td>
            </tr>
            <tr>
              <tr>
    <td class="label">Amount</td>
    <td class="value">
        <strong>
           @if($displayCurrency == 'USD')
    ${{ number_format($invoice->total_amount, 2) }}
@else
    INR {{ number_format($invoice->grand_total, 2) }}
@endif
        </strong>
    </td>
</tr>
            </tr>
            <tr>
                <td class="label">Date</td>
                <td class="value">{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') }}</td>
            </tr>
            @if($invoice->gst_number)
            <tr>
                <td class="label">GST No</td>
                <td class="value">{{ $invoice->gst_number }}</td>
            </tr>
            @endif
            @if($invoice->sales_person)
            <tr>
                <td class="label">Sales Person</td>
                <td class="value">{{ $invoice->sales_person }}</td>
            </tr>
            @endif
            @if($invoice->is_revision && $invoice->revision_number > 0)
            <tr>
                <td class="label">Revision</td>
                <td class="value">R{{ $invoice->revision_number }}</td>
            </tr>
            @endif
        </table>
    </div>

    <!-- ✅ Second Part: "Kindly find the attached invoice. ..." -->
    @if(!empty($after))
        <div class="content">
            {!! nl2br(e($after)) !!}
        </div>
    @endif

    <div class="footer">
        This is a system generated email. Please do not reply to this email.<br>
        &copy; {{ date('Y') }} Apple Holidays. All rights reserved.
    </div>
</body>
</html>