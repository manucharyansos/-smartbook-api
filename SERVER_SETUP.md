# SmartBook API — Server Deployment Guide

## Prerequisites
- PHP 8.2+
- MySQL 8.0+
- Composer
- Node.js 20+ (optional, for assets)

## Step 1 — Configure .env
Copy `.env` and fill in ALL values marked `YOUR_*`:
```
APP_URL=https://your-api-domain.com
APP_FRONTEND_URL=https://your-frontend-domain.com
DB_DATABASE=smartbook
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
SESSION_DOMAIN=.your-domain.com
SANCTUM_STATEFUL_DOMAINS=your-frontend-domain.com
CORS_ALLOWED_ORIGINS=https://your-frontend-domain.com
```

## Step 2 — Install & Migrate
```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate          # only if APP_KEY is empty
php artisan migrate --force
php artisan db:seed --class=PlansSeeder
php artisan db:seed --class=AdminSeeder
php artisan storage:link
```

## Step 3 — Cache for production
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Step 4 — Queue worker (cron)
Add to crontab:
```
* * * * * cd /path/to/smartbook-api && php artisan schedule:run >> /dev/null 2>&1
```

Start queue worker (supervisor recommended):
```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

## Step 5 — Nginx config (example)
```nginx
server {
    listen 443 ssl;
    server_name your-api-domain.com;
    root /path/to/smartbook-api/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

## Step 6 — File permissions
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

## Important Notes
- `APP_DEBUG=false` in production — already set
- `LOG_LEVEL=warning` — already set (saves disk)
- Billing is in `mock` mode — set `BILLING_PROVIDER_MODE=live` + IDBank credentials when ready
- Social auth (Google/Facebook) not yet integrated — buttons hidden by default
