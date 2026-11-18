# CVCarts Deployment Guide

## 1. Prepare the host

1. Use PHP 8.2 or newer with `pdo_mysql`, `mysqli`, `openssl`, `mbstring`, `fileinfo`, and GD/image support as available.
2. Use MySQL 8 or MariaDB 10.5 or newer with InnoDB and foreign keys enabled.
3. Enable Apache `mod_rewrite` and `mod_headers`, or configure the same HTTPS/security rules in the host panel.
4. Install Composer dependencies:

   ```sh
   composer install --no-dev --optimize-autoloader --no-interaction
   ```

## 2. Configure environment

Copy `.env.example` to `.env` outside source control and set:

- `APP_ENV=production`
- `APP_BASE_URL=https://your-domain.example` or the HTTPS subdirectory
- `APP_KEY` to a unique 64-character random secret
- hosted database host, name, user, and password
- hosted SMTP settings and authorized sender
- production administrator email

Do not commit `.env`, database credentials, SMTP passwords, or private uploads.

## 3. Database

Back up the existing hosting database first. Import `database/cscqc_ecommerce.sql` through phpMyAdmin or MySQL CLI. Alternatively, create an empty database and run:

```sh
php database/setup.php
php database/init_admin.php
```

The setup and migration commands must be run from CLI only.

## 4. Deploy files

Upload the project with `vendor/` or run Composer on the host. Exclude local `.env`, `.git`, local backups, and private uploaded records unless intentionally migrating them.

Recommended write permissions:

- project directories: 755
- PHP/config files: 644
- `uploads/`: 775 for the web-server account
- `uploads/payment-proofs/`: 775; executable access must remain denied

## 5. Verify

1. Open the HTTPS domain and confirm it redirects HTTP to HTTPS.
2. Confirm `.env`, SQL export, Composer files, `config/`, `includes/`, `database/`, and `vendor/` are denied or inaccessible.
3. Run administrator initialization once.
4. Sign in and change the temporary administrator password.
5. Test student registration, email verification, login, shopping, cart, checkout, proof upload, order status, receipt, and logout.
6. Test admin inventory, users, payment review, status changes, schedule, QR claim, sales, and settings.
7. Run an SMTP preflight and send a real test email.
8. Review Apache/PHP error logs and confirm visitors do not see PHP exception messages.
