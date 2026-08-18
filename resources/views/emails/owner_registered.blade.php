<!doctype html>
<html lang="hy">
<head>
    <meta charset="utf-8" />
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5;">
    <h2 style="margin:0 0 12px 0;">Բարի գալուստ 👋</h2>

    <p>Բարև, {{ $owner->name }}!</p>

    <p>
        Ձեր բիզնեսը հաջողությամբ գրանցվել է համակարգում․<br>
        <strong>{{ $business->name }}</strong> ({{ $business->business_type }})
    </p>

    <p>
        Փորձնական շրջանը՝ <strong>{{ $trialDays }} օր</strong>։
        Կարող եք մտնել և ավարտել onboarding-ը, ավելացնել ծառայություններ և աշխատակիցներ։
    </p>

    <p>
        Եթե հարց ունեք՝ պատասխանեք այս նամակին կամ գրեք WhatsApp՝
        <strong>{{ config('app.support_whatsapp', '+37498408879') }}</strong>
    </p>

    <p style="color:#666; font-size:12px; margin-top:18px;">Vizit</p>
</body>
</html>
