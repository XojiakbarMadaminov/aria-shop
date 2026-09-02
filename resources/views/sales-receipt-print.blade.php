@php use App\Support\ReceiptData; @endphp
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Savdo Cheki print #{{ $sale->id }}</title>
    <style>
        @page {margin: 0; }
        @font-face {
            font-family: 'DejaVu Sans';
            src: url("{{ asset('fonts/DejaVuSans.ttf') }}") format('truetype');
        }
        body {
            font-family: 'DejaVu Sans', monospace;
            font-size: 13px;
            margin: 0;
            padding: 8px;
        }
        .receipt {
            width: 60mm;
            margin: 0 auto;
            padding-left: 2mm;
            padding-right: 2mm;
            page-break-inside: avoid;
        }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .line { border-bottom: 1px dashed #000; margin: 2mm 0; }
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin: 2px 0;
            page-break-inside: avoid;
        }
        .item-name { flex: 1; }
        .item-total { text-align: right; min-width: 24mm; }
        .receipt-footer-printonly img {
            display: block;
            margin: 0 auto 2mm auto;
            max-width: 40mm;
            max-height: 40mm;
        }
    </style>
</head>
<body>
    @php
        $receiptData = ReceiptData::fromSale($sale);
    @endphp
    <div class="receipt" id="sale-receipt">
        @include('receipts.partials.default', [
            'receiptData' => $receiptData,
            'qrPath' => asset('images/taplink.png'),
        ])
    </div>

    <script>
        window.addEventListener('load', () => {
            window.print();
            setTimeout(() => window.close(), 300);
        });
    </script>
</body>
</html>
