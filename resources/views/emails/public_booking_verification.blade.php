<!DOCTYPE html>
<html lang="hy">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>Vizit հաստատման կոդ</title>
</head>
<body style="margin:0;padding:0;background:#f5f2ee;font-family:Arial,Helvetica,sans-serif;color:#28142b;">
@php
  $tz = $booking->business?->timezone ?: config('app.timezone');
  $localStart = $booking->starts_at?->copy()?->timezone($tz);
  $services = $booking->items->count()
      ? $booking->items->map(fn($item) => $item->service?->name)->filter()->values()
      : collect([$booking->service?->name])->filter();
@endphp
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
  {{ $booking->business?->name }} ամրագրման հաստատման կոդը՝ {{ $code }}։
</div>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f5f2ee;">
  <tr>
    <td align="center" style="padding:28px 12px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:#ffffff;border:1px solid #e7ded7;border-radius:24px;overflow:hidden;box-shadow:0 14px 36px rgba(43,13,53,.08);">
        <tr>
          <td style="height:6px;background:#6d2a63;font-size:0;line-height:0;">&nbsp;</td>
        </tr>
        <tr>
          <td style="padding:28px 28px 12px;">
            <div style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:#2b0d35;">Vizit.am</div>
            <div style="margin-top:6px;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#98641f;">Անվտանգ հաստատում</div>
          </td>
        </tr>
        <tr>
          <td style="padding:10px 28px 0;">
            <h1 style="margin:0;font-size:26px;line-height:1.28;color:#28142b;">Հաստատեք ձեր ամրագրումը</h1>
            <p style="margin:14px 0 0;font-size:15px;line-height:1.7;color:#5f5262;">
              Դուք սկսել եք ամրագրում <strong style="color:#28142b;">{{ $booking->business?->name }}</strong>-ում։
              Վերադարձեք Vizit-ի բաց ամրագրման էջ և մուտքագրեք այս մեկանգամյա կոդը։
            </p>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:24px 28px 10px;">
            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#756778;">Հաստատման կոդ</div>
            <div style="margin-top:10px;display:inline-block;padding:17px 22px;border:1px solid #dcc89f;border-radius:18px;background:#fff8e9;color:#2b0d35;font-family:Arial,Helvetica,sans-serif;font-size:34px;font-weight:800;letter-spacing:8px;line-height:1;">{{ $code }}</div>
            <div style="margin-top:12px;font-size:12px;line-height:1.5;color:#756778;">Վավեր է մինչև {{ $expires->copy()->timezone($tz)->format('d.m.Y H:i') }}</div>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 28px 8px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #eee5df;border-radius:18px;background:#fcfaf8;">
              <tr>
                <td style="padding:14px 16px;border-bottom:1px solid #eee5df;font-size:13px;color:#756778;">Ծառայություն</td>
                <td align="right" style="padding:14px 16px;border-bottom:1px solid #eee5df;font-size:14px;font-weight:700;line-height:1.5;color:#28142b;">{{ $services->implode(', ') ?: 'Ծառայություն' }}</td>
              </tr>
              @if($booking->staff?->name)
              <tr>
                <td style="padding:14px 16px;border-bottom:1px solid #eee5df;font-size:13px;color:#756778;">Մասնագետ</td>
                <td align="right" style="padding:14px 16px;border-bottom:1px solid #eee5df;font-size:14px;font-weight:700;line-height:1.5;color:#28142b;">{{ $booking->staff?->name }}</td>
              </tr>
              @endif
              <tr>
                <td style="padding:14px 16px;font-size:13px;color:#756778;">Ամսաթիվ և ժամ</td>
                <td align="right" style="padding:14px 16px;font-size:14px;font-weight:700;color:#28142b;">{{ $localStart?->format('d.m.Y H:i') ?? '—' }}</td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 28px 28px;">
            <p style="margin:0;font-size:13px;line-height:1.7;color:#756778;">
              Եթե դուք չեք սկսել այս ամրագրումը, անտեսեք նամակը։ Կոդը ոչ մեկին մի փոխանցեք․ Vizit-ը երբեք չի խնդրի այն հեռախոսով կամ հաղորդագրությամբ։
            </p>
            <p style="margin:14px 0 0;padding-top:14px;border-top:1px solid #eee5df;font-size:11px;line-height:1.6;color:#948797;">
              Սա Vizit.am-ի ավտոմատ ծառայողական նամակ է՝ ուղարկված միայն ձեր սկսած ամրագրումը հաստատելու համար։
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
