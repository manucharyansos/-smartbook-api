# Reminder automation setup

## 1) Enable the scheduler
Run Laravel's scheduler every minute on the server:

```bash
* * * * * cd /path/to/smartbook-api && php artisan schedule:run >> /dev/null 2>&1
```

The app now schedules `php artisan reminders:dispatch-due` automatically every minute.

## 2) Configure SMS / WhatsApp delivery

Development-safe mode:

```env
SMS_DRIVER=log
```

Real Twilio delivery:

```env
SMS_DRIVER=twilio
TWILIO_SID=your_twilio_sid
TWILIO_TOKEN=your_twilio_token
TWILIO_FROM=+123456789
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
SMS_FROM=+123456789
WHATSAPP_FROM=whatsapp:+14155238886
```

## 3) Optional manual runs

```bash
php artisan reminders:dispatch-due --dry-run
php artisan reminders:dispatch-due
```

## 4) Notes
- `internal` reminders are marked delivered inside the app.
- `email` is sent immediately by the dispatch command.
- `sms` and `whatsapp` use the configured SMS driver.
- When `SMS_DRIVER=log`, deliveries are written to logs instead of calling a real provider.
