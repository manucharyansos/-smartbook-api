<!DOCTYPE html>
<html lang="hy">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ամրագրումը չեղարկվել է</title>
</head>
<body style="margin:0;padding:0;background:#EEF2F0;font-family:Arial,Helvetica,sans-serif;color:#10201D;">
@php
  $tz = $booking->business?->timezone ?: config('app.timezone');
  $localStart = $booking->starts_at?->copy()?->timezone($tz);
  $services = $booking->items->count()
      ? $booking->items->map(fn($item) => $item->service?->name)->filter()->values()
      : collect([$booking->service?->name ?: ($booking->business?->isRental() ? $booking->business->name : null)])->filter();
@endphp
<div style="max-width:680px;margin:0 auto;padding:28px 14px;">
  <div style="background:#B3433F;border-radius:30px;padding:34px 28px;color:#fff;box-shadow:0 24px 60px rgba(16,32,29,.14);">
    <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.85;">Vizit</div>
    <h1 style="margin:14px 0 10px;font-size:30px;line-height:1.15;">Ամրագրումը չեղարկվել է</h1>
    <p style="margin:0;font-size:15px;line-height:1.75;color:rgba(255,255,255,.92);">Ձեր ամրագրումը հաջողությամբ չեղարկվել է։ Եթե սա արվել է սխալմամբ, կարող եք ստեղծել նոր ամրագրում ցանկացած հարմար պահին։</p>
  </div>

  <div style="margin-top:18px;background:#ffffff;border-radius:28px;padding:28px;border:1px solid #DFE6E3;box-shadow:0 18px 42px rgba(15,23,42,.08);">
    <div style="display:inline-block;padding:8px 14px;border-radius:999px;background:#FBEAE8;color:#8E2F2C;font-size:12px;font-weight:700;">Չեղարկված</div>
    <div style="margin-top:18px;border-radius:24px;border:1px solid #DFE6E3;background:#F7F8F6;padding:8px 18px;">
      <div style="padding:14px 0;border-bottom:1px solid #DFE6E3;"><div style="font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">Բիզնես</div><div style="margin-top:6px;font-size:20px;font-weight:700;color:#10201D;line-height:1.45;word-break:break-word;">{{ $booking->business?->name }}</div></div>
      <div style="padding:14px 0;border-bottom:1px solid #DFE6E3;"><div style="font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">{{ $booking->business?->isRental() ? 'Տարածք' : 'Ծառայություններ' }}</div><div style="margin-top:6px;font-size:18px;font-weight:700;color:#10201D;line-height:1.55;word-break:break-word;">{{ $services->implode(', ') ?: 'Ծառայություն' }}</div></div>
      @if($booking->staff?->name)
      <div style="padding:14px 0;border-bottom:1px solid #DFE6E3;"><div style="font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">Մասնագետ</div><div style="margin-top:6px;font-size:18px;font-weight:700;color:#10201D;line-height:1.45;word-break:break-word;">{{ $booking->staff?->name }}</div></div>
      @endif
      <div style="padding:14px 0;"><div style="font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">Ամսաթիվ և ժամ</div><div style="margin-top:6px;font-size:18px;font-weight:700;color:#10201D;line-height:1.45;word-break:break-word;">{{ $localStart?->format('d.m.Y H:i') ?? '—' }}</div></div>
    </div>

    <p style="margin:22px 0 0;font-size:13px;line-height:1.75;color:#55635F;">
      Եթե ցանկանում եք, կարող եք նույն պահին ստեղծել նոր ամրագրում և ընտրել մեկ այլ ազատ ժամ։
    </p>
  </div>
</div>
</body>
</html>
