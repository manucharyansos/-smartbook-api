<!doctype html>
<html lang="hy">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Նոր ամրագրում</title>
</head>
<body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px;background:#f8fafc;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;border-radius:24px;overflow:hidden;background:#ffffff;border:1px solid #e2e8f0;">
            <tr><td style="padding:24px 28px;background:#0f172a;color:#ffffff;">
                <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.8;">Vizit</div>
                <h1 style="margin:10px 0 0;font-size:24px;line-height:1.25;">Ձեզ համար ամրագրում է ստեղծվել</h1>
            </td></tr>
            <tr><td style="padding:28px;">
                <p style="margin:0 0 18px;line-height:1.7;">Բարև, {{ $booking->client_name }}։ {{ $booking->business?->name ?? 'Բիզնեսը' }}-ում ձեզ համար ստեղծվել է նոր ամրագրում։</p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0 8px;">
                    <tr><td style="padding:12px 14px;background:#f1f5f9;border-radius:14px;"><strong>Ծառայություն՝</strong> {{ $booking->service?->name ?? 'Ծառայություն' }}</td></tr>
                    <tr><td style="padding:12px 14px;background:#f1f5f9;border-radius:14px;"><strong>Մասնագետ՝</strong> {{ $booking->staff?->name ?? 'Կնշվի բիզնեսի կողմից' }}</td></tr>
                    <tr><td style="padding:12px 14px;background:#f1f5f9;border-radius:14px;"><strong>Ամսաթիվ՝</strong> {{ optional($booking->starts_at)->timezone($booking->business?->effectiveTimezone() ?? 'Asia/Yerevan')->format('d.m.Y H:i') }}</td></tr>
                    <tr><td style="padding:12px 14px;background:#f1f5f9;border-radius:14px;"><strong>Կարգավիճակ՝</strong> {{ $booking->status }}</td></tr>
                </table>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
