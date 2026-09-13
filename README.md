# Moscow Traders Wholesale

Moscow Traders Wholesale is a Laravel-based ecommerce, point-of-sale, and inventory management application. The public storefront, guest checkout, Filament admin, purchasing, sales, stock, customer and supplier ledgers, cashier shifts, receipts, and reporting all use the same database.

## Requirements

- PHP 8.2 or newer
- Composer
- Node.js and npm
- PHP extensions: PostgreSQL PDO, GD, Intl, Mbstring, XML, cURL, Fileinfo, and ZIP
- PHP SQLite extension for the default local setup

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/moscow_traders_wholesale.sqlite
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

The default local database is `database/moscow_traders_wholesale.sqlite`. To use MySQL or PostgreSQL instead, replace the `DB_*` values in `.env` and run `php artisan migrate --seed` against the new database.

The demo seeder creates these local accounts:

- Administrator: `admin@moscowtraders.local`
- Cashier: `cashier@moscowtraders.local`
- Password for both: `password`

Change or remove the demo credentials before deploying the application.

After starting Laravel, open the storefront at `http://127.0.0.1:8000` and the administration panel at `http://127.0.0.1:8000/admin`.

## Storefront management

- Use **Products** in the admin panel to set the online sale price, visibility, featured status, short description, and product images.
- Use **Categories** to upload category images.
- Use **Storefront Settings** to select the online branch and warehouse and manage WhatsApp, social links, delivery methods, and payment methods.
- Website checkouts appear in **Sales** with the `Website` channel. Updating a website order to paid records its customer payment; cancelling an unpaid website order restores inventory.
- Checkout currently supports configurable offline payment methods. No online card gateway is bundled.

## Development

Run the Laravel server, queue worker, log viewer, and Vite development server together:

```bash
composer run dev
```

## Quality checks

```bash
composer test
npm run test:frontend
npm run build
vendor/bin/pint --test
```

## Product import

```bash
php artisan moscow-traders-wholesale:import-products COMPANY_UUID products.csv
```

## DigitalOcean production deployment

Production templates are available in [`deploy/`](deploy/) and [`.env.production.example`](.env.production.example). Configure the PostgreSQL host, port, database, username, and password as secrets in the hosting environment; SSL mode is set to `require`. Generate the application key on the application server. Never commit the real `.env` file or database credentials.

For a first deployment on an Ubuntu droplet with Nginx and PHP-FPM already installed:

```bash
sudo mkdir -p /var/www/islandthrift
sudo chown "$USER":www-data /var/www/islandthrift
git clone https://github.com/Rannamaari/islandthrift.git /var/www/islandthrift
cd /var/www/islandthrift
cp .env.production.example .env
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan key:generate
sudo chown -R "$USER":www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} +
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} +
```

Edit `.env`, enter the real domain, database name, and database password, then run:

```bash
ISLAND_THRIFT_DIR=/var/www/islandthrift bash deploy/deploy.sh
```

The deployment script installs production dependencies, builds assets, places Laravel briefly into maintenance mode, runs migrations without demo seeders, creates the public storage link, caches the application configuration, and restarts queue workers. If the application is already installed elsewhere, set `ISLAND_THRIFT_DIR` to that directory.

Install the included Nginx and queue worker templates. They target this droplet's PHP 8.4 socket and `/var/www/islandthrift`; adjust those values first if the server layout changes:

```bash
sudo cp deploy/nginx-islandthrift.conf /etc/nginx/sites-available/islandthrift
sudo ln -s /etc/nginx/sites-available/islandthrift /etc/nginx/sites-enabled/islandthrift
sudo nginx -t
sudo systemctl reload nginx
sudo cp deploy/islandthrift-worker.service /etc/systemd/system/islandthrift-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now islandthrift-worker
```

After DNS points to the droplet, issue the HTTPS certificate and confirm Laravel's health endpoint:

```bash
sudo certbot --nginx -d islandthrift.micronet.mv
curl --fail https://islandthrift.micronet.mv/up
```

Do not run `php artisan migrate --seed` in production because the demo seeder creates sample products and known development passwords.

## Dhiraagu SMS and customer OTP

SMS is dry-run by default. Configure these values in `.env` only; never commit a real authorization key:

```env
DHIRAAGU_SMS_BASE_URL=https://messaging.dhiraagu.com.mv/v1/api
DHIRAAGU_SMS_AUTH_KEY=<base64-of-dhiraagu-username-and-password>
DHIRAAGU_SMS_SOURCE=Micronet
DHIRAAGU_SMS_DRY_RUN=true
DHIRAAGU_SMS_CHUNK_SIZE=200
DHIRAAGU_SMS_TIMEOUT=20
```

For local testing, leave dry-run enabled, visit `/register`, submit a valid-format Maldives number, and use the dry-run code displayed on the verification page. Administrators can test broadcasts under **Administration → SMS Messaging**; no gateway request is made in dry-run mode. In production, customer OTP registration remains unavailable while dry-run is enabled. Add rotated, valid credentials and set `DHIRAAGU_SMS_DRY_RUN=false` only when ready to send real messages, then run `php artisan optimize`.

Gateway request example using placeholders only:

```bash
curl --request POST 'https://messaging.dhiraagu.com.mv/v1/api/sms' \
  --header 'Content-Type: application/json' \
  --data '{"destination":["9607779493"],"content":"Your message here","source":"Micronet","authorizationKey":"<base64-authorization-key>"}'
```

## License

This project is proprietary unless a separate license is provided by the repository owner.
