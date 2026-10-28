<!doctype html>
@php
$locale = app()->getLocale();
$text = [
    'hy' => ['title' => 'Շարունակում ենք Idram-ի վճարման էջում', 'amount' => 'Վճարման գումարը', 'button' => 'Շարունակել Idram-ով', 'help' => 'Եթե էջը ինքնաբերաբար չի բացվում, սեղմեք կոճակը։'],
    'ru' => ['title' => 'Переходим на страницу оплаты Idram', 'amount' => 'Сумма к оплате', 'button' => 'Продолжить в Idram', 'help' => 'Если переход не произошёл автоматически, нажмите кнопку.'],
    'en' => ['title' => 'Continue to the Idram payment page', 'amount' => 'Amount to pay', 'button' => 'Continue with Idram', 'help' => 'If the page does not open automatically, press the button.'],
][$locale] ?? ['title' => 'Idram payment', 'amount' => 'Amount', 'button' => 'Continue', 'help' => 'Press the button to continue.'];
@endphp
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Vizit · Idram</title>
    <style nonce="{{ $nonce }}">
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f2f8f7;color:#123c3c;font:16px/1.6 system-ui,sans-serif}
        main{max-width:420px;margin:20px;padding:32px;border:1px solid #d9e8e5;border-radius:24px;background:white;box-shadow:0 16px 50px #123c3c0a}
        .brand{font-weight:800;color:#147d79}h1{font-size:22px}p{color:#526b6b}button{border:0;border-radius:12px;padding:14px 20px;background:#147d79;color:white;font:inherit;font-weight:700;cursor:pointer}
    </style>
</head>
<body>
<main>
    <div class="brand">Vizit.am · Idram</div>
    <h1>{{ $text['title'] }}</h1>
    <p>{{ $text['amount'] }}: <strong>{{ $fields['EDP_AMOUNT'] }} AMD</strong>։</p>
    <form id="idram-payment" method="POST" action="{{ \App\Services\Billing\IdramGateway::CHECKOUT_URL }}" accept-charset="UTF-8">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <button type="submit">{{ $text['button'] }}</button>
    </form>
    <p>{{ $text['help'] }}</p>
</main>
<script nonce="{{ $nonce }}">document.getElementById('idram-payment').submit();</script>
</body>
</html>
