<!DOCTYPE html>
<html lang="hy">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ամրագրման ժամը փոխվել է</title>
</head>
<body style="margin:0;padding:0;background:#F7F8F6;font-family:Arial,Helvetica,sans-serif;color:#10201D;">
@php
  $timezone = $booking->business?->effectiveTimezone() ?? 'Asia/Yerevan';
  $oldLocal = $oldStart?->copy()?->timezone($timezone);
  $newLocal = $booking->starts_at?->copy()?->timezone($timezone);
  $services = $booking->items->count()
      ? $booking->items->map(fn($item) => $item->service?->name)->filter()->values()
      : collect([$booking->service?->name ?: ($booking->business?->isRental() ? $booking->business->name : null)])->filter();
@endphp
<div style="max-width:680px;margin:0 auto;padding:28px 14px;">
  <div style="background:#0F766E;border-radius:30px;padding:34px 28px;color:#fff;box-shadow:0 24px 60px rgba(16,32,29,.14);">
    <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#FDEBD6;">Vizit</div>
    <h1 style="margin:14px 0 10px;font-size:30px;line-height:1.15;">Ձեր ամրագրման ժամը փոխվել է</h1>
    <p style="margin:0;font-size:15px;line-height:1.75;color:rgba(255,255,255,.82);">Նոր ժամը հաստատված է։ Բիզնեսը և մասնագետը արդեն ծանուցվել են փոփոխության մասին։</p>
  </div>

  <div style="margin-top:18px;background:#fff;border-radius:28px;padding:28px;border:1px solid #DFE6E3;box-shadow:0 18px 42px rgba(75,36,52,.08);">
    <div style="font-size:20px;font-weight:700;">{{ $booking->business?->name }}</div>
    <div style="margin-top:16px;border-radius:22px;background:#F7F8F6;border:1px solid #DFE6E3;padding:18px;">
      <div style="font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">{{ $booking->business?->isRental() ? 'Տարածք' : 'Ծառայություն' }}</div>
      <div style="margin-top:6px;font-size:17px;font-weight:700;">{{ $services->implode(', ') ?: 'Ծառայություն' }}</div>
      <div style="margin-top:16px;font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">Նախկին ժամ</div>
      <div style="margin-top:6px;font-size:16px;color:#55635F;text-decoration:line-through;">{{ $oldLocal?->format('d.m.Y H:i') ?? '—' }}</div>
      <div style="margin-top:16px;font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">Նոր ժամ</div>
      <div style="margin-top:6px;font-size:20px;font-weight:700;color:#0F766E;">{{ $newLocal?->format('d.m.Y H:i') ?? '—' }}</div>
      @if($booking->staff?->name)
      <div style="margin-top:16px;font-size:12px;color:#55635F;text-transform:uppercase;letter-spacing:.08em;">Մասնագետ</div>
      <div style="margin-top:6px;font-size:17px;font-weight:700;">{{ $booking->staff?->name }}</div>
      @endif
    </div>

    <div style="margin-top:24px;text-align:center;">
      <a href="{{ $manageLink }}" style="display:inline-block;padding:14px 22px;border-radius:16px;background:#10201D;color:#fff;text-decoration:none;font-weight:700;">Բացել ամրագրումը</a>
    </div>

    <p style="margin:22px 0 0;font-size:13px;line-height:1.75;color:#55635F;">Փոփոխությունը կրկին հնարավոր է միայն բիզնեսի սահմանած ժամկետի ընթացքում և ազատ ժամերի առկայության դեպքում։</p>
  </div>
</div>
</body>
</html>
