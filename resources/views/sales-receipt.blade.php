<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Savdo Cheki 123 #{{ $sale->id }}</title>
    <style>
        @page { margin: 10px; }
        @font-face {
            font-family: 'DejaVu Sans';
            src: url("{{ public_path('fonts/DejaVuSans.ttf') }}") format('truetype');
        }
        body {
            font-family: 'DejaVu Sans', monospace;
            font-size: 13px;
            color: #000;
            margin: 0;
            background: #fff;
        }
        .receipt {
            width: 60mm;
            margin: 0 auto;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .line { border-bottom: 1px dashed #000; margin: 6px 0; }
        .item-row { display: flex; justify-content: space-between; align-items: flex-end; margin: 2px 0; }
        .item-row .item-name { flex: 1; }
        .item-row .item-total { text-align: right; min-width: 60px; }
        .receipt-footer-printonly img { display: block; margin: 0 auto; }
    </style>
    @php
        $receiptData  = \App\Support\ReceiptData::fromSale($sale);
        $qrPublicPath = public_path('images/taplink-20260927.png');
        $qrPath       = is_file($qrPublicPath) ? ('file://' . $qrPublicPath) : null;
    @endphp
</head>
<body>
    <div class="receipt">
        @include('receipts.partials.default', [
            'receiptData' => $receiptData,
            'qrPath' => $qrPath,
        ])
    </div>
</body>
</html>
