<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 0; }
        @font-face {
            font-family: 'DejaVu Sans';
            src: url("{{ public_path('fonts/DejaVuSans.ttf') }}") format('truetype');
        }

        body {
            font-family: 'DejaVu Sans', monospace;
            font-size: 12px;
            width: 176pt; /* 62mm */
            margin: 0;
            padding: 10px;
        }


        .line { margin: 2px 0; }
        .center { text-align: center; margin: 3px 0; }
        .divider { border-top: 1px dashed black; margin: 4px 0; }

        img.logo {
            width: 70px;
            height: auto;
            margin: 2px auto;
            display: block;
        }

        .mini {
            font-size: 10px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="center">
    <img src="{{ public_path('images/million_black_white_transparent.png') }}" alt="<<Mir obuv 9494>>" class="logo">
</div>

<div class="center">*** QARZDORLIK CHEKI ***</div>

<div class="line">Do`kon: {{ config('app.store_name') }}</div>
<div class="line">Sana:   {{ now()->format('d.m.Y') }}</div>
<div class="line">Ism:    {{ $debtor->client?->full_name }}</div>
<div class="line">Tel:    {{ $debtor->client?->phone }}</div>
<div class="line">Valyuta: {{ strtoupper($debtor->currency) }}</div>
<div class="line">Joriy qarz:  {{ number_format($debtor->amount, 0, '.', ' ') }}</div>

@if ($debtor->transactions->isNotEmpty())
    <div class="divider"></div>
    <div class="line"><strong>Tranzaksiyalar:</strong></div>
    @foreach ($debtor->transactions as $tx)
        <div class="line">
            [{{ \Carbon\Carbon::parse($tx->date)->format('d.m.Y') }}]
            {{ $tx->type === 'debt' ? '+' : '-' }}
            {{ number_format($tx->amount, 0, '.', ' ') }}
        </div>
    @endforeach
@endif

<div class="divider"></div>

<div class="center">
    <img src="{{ public_path('images/taplink-20260927.png') }}" class="logo">
</div>

<div class="divider"></div>
<div class="line">Tel: {{ config('app.phone') }}</div>
<div class="line">Karta: {{ config('app.card') }}</div>

<div class="divider"></div>

<div class="center mini">Tizim hamkori - @oson_pos</div>

</body>
</html>
