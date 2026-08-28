<!DOCTYPE html>
<html lang="hy">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ամրագրումը հաստատված է</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
@php
  $tz = $booking->business?->timezone ?: config('app.timezone');
  $localStart = $booking->starts_at?->copy()?->timezone($tz);
  $services = $booking->items->count()
      ? $booking->items->map(fn($item) => $item->service?->name)->filter()->values()
      : collect([$booking->service?->name])->filter();
@endphp
<div style="max-width:680px;margin:0 auto;padding:28px 14px;">
  <div style="background:linear-gradient(135deg,#059669 0%,#10b981 45%,#38bdf8 100%);border-radius:30px;padding:34px 28px;color:#fff;box-shadow:0 24px 60px rgba(15,23,42,.16);">
    <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.85;">Vizit</div>
    <h1 style="margin:14px 0 10px;font-size:30px;line-height:1.15;">Ձեր ամրագրումը հաստատված է</h1>
    <p style="margin:0;font-size:15px;line-height:1.75;color:rgba(255,255,255,.92);">Ամրագրումը հաջողությամբ հաստատվել է։ Ստորև կարող եք տեսնել հիմնական տվյալները և բացել կառավարման էջը։</p>
  </div>

  <div style="margin-top:18px;background:#ffffff;border-radius:28px;padding:28px;border:1px solid #e2e8f0;box-shadow:0 18px 42px rgba(15,23,42,.08);">
    <div style="display:inline-block;padding:8px 14px;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:700;">Հաստատված</div>
    <div style="margin-top:18px;border-radius:24px;border:1px solid #e2e8f0;background:#f8fafc;padding:8px 18px;">
      <div style="padding:14px 0;border-bottom:1px solid #e2e8f0;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Բիզնես</div><div style="margin-top:6px;font-size:20px;font-weight:700;color:#0f172a;line-height:1.45;word-break:break-word;">{{ $booking->business?->name }}</div></div>
      <div style="padding:14px 0;border-bottom:1px solid #e2e8f0;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Ծառայություններ</div><div style="margin-top:6px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.55;word-break:break-word;">{{ $services->implode(', ') ?: 'Ծառայություն' }}</div></div>
      @if($booking->staff?->name)
      <div style="padding:14px 0;border-bottom:1px solid #e2e8f0;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Մասնագետ</div><div style="margin-top:6px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.45;word-break:break-word;">{{ $booking->staff?->name }}</div></div>
      @endif
      <div style="padding:14px 0;border-bottom:1px solid #e2e8f0;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Ամսաթիվ և ժամ</div><div style="margin-top:6px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.45;word-break:break-word;">{{ $localStart?->format('d.m.Y H:i') ?? '—' }}</div></div>
      <div style="padding:14px 0;"><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Ամրագրման կոդ</div><div style="margin-top:6px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.45;word-break:break-word;">{{ $booking->booking_code }}</div></div>
    </div>

    <div style="margin-top:24px;text-align:center;">
      <a href="{{ $manageLink }}" style="display:inline-block;padding:14px 22px;border-radius:16px;background:#0f172a;color:#ffffff;text-decoration:none;font-weight:700;">Կառավարել ամրագրումը</a>
    </div>

    <p style="margin:22px 0 0;font-size:13px;line-height:1.75;color:#64748b;">
      Այս հղումով կարող եք տեսնել, տեղափոխել կամ չեղարկել ամրագրումը, քանի դեռ այն հասանելի է հյուրային մուտքով։
    </p>
  </div>
</div>
</body>
</html>
