# CVCarts Deployment Guide

## 1. Prepare the hosting account

1. In the InfinityFree control panel, select an available PHP version supported by this application (PHP 8.2 or newer) and confirm `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`, and PHP sessions are enabled.
2. Create the MySQL database in the hosting control panel. Use the exact database host, database name, username, and password shown there; the database host is not necessarily `localhost`.
3. Use the control panel's phpMyAdmin to select the newly created database.

InfinityFree accounts may not provide shell access or Composer. If Composer is unavailable on the account, run `composer install --no-dev --optimize-autoloader --no-interaction` on your computer and upload the resulting `vendor/` directory, including its `.htaccess`.

## 2. Configure the application

1. Copy `.env.example` to `.env` in the project root.
2. Set `APP_ENV=production`, the exact hosted `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`, and `APP_BASE_URL` to the final HTTPS URL (including a subdirectory if applicable).
3. Set a unique random `APP_KEY` of at least 32 characters (the example recommends 64 hexadecimal characters). Configure SMTP credentials and `ADMIN_EMAIL` as well.
4. Do not send `.env` or its secrets in chat, email, source control, or support requests. The root `.htaccess` denies direct requests for it.

Do not use the example values or local XAMPP credentials on the hosted site. A production database connection is deliberately refused when any database credential is missing.

## 3. Create or migrate the database

1. Take a backup of any existing hosted database before changing it.
2. For a new installation with no records to migrate, select the created database in phpMyAdmin and import the tracked, schema-only `database/cscqc_ecommerce.sql`.
3. To migrate the current local users, products, carts, and orders, use the private `database/infinityfree-migration.sql` export prepared for this deployment. It contains both schema and business data, so import **only this file** into the newly created empty database (do not import the schema-only file first). It does not contain `DROP TABLE`, database-selection statements, password-reset/verification tokens, login/IP attempt logs, or delivery/realtime logs. It does contain customer and order data: upload it only to phpMyAdmin over HTTPS, do not commit/email/share it, and delete it from the computer and hosting account after the import. The file is intentionally excluded from source control.
4. Take a backup of the source and verify the hosted database is empty before importing. If source records change after this snapshot, create a fresh protected export before the migration rather than reusing a stale file.
5. Keep the existing `APP_KEY` when moving the same application so previously generated order QR codes remain verifiable. Keep it private. Since short-lived verification/reset tokens are intentionally omitted, pending users will need a fresh verification email and outstanding password-reset links will no longer work.
6. The application applies outstanding versioned migrations on the first request after connecting. They are guarded by migration records; a fully migrated database is not altered on each request. Take a backup before first launch.
7. `database/setup.php` and `database/init_admin.php` are command-line-only utilities. Do not remove that guard or make them web-accessible. Sign in with an existing migrated administrator. If there is no active administrator and the host has no CLI, provision one through a trusted, secure database-administration process using a `password_hash()` result; never insert or share a plaintext password.

## 4. Expired student registration cleanup

Unverified student registrations and their verification tokens are deleted after the 15-minute verification period. Requests to registration, verification, login, the Admin dashboard, and Admin student pages also run a bounded cleanup as a fallback. If the host supports scheduled CLI tasks, run `php /path/to/application/database/cleanup_expired_registrations.php` every minute using the same environment configuration as the web application. The script is CLI-only; do not make it web-accessible. If the host does not provide cron or scheduled tasks, the request-triggered cleanup remains enabled.

## 5. Upload files

Upload the application contents (not an extra enclosing project folder) into the domain's document root, typically `htdocs/`. Include `vendor/`; do not upload `.git/`, private migration exports, or local backups. Upload the configured `.env` only to the project root, and verify hidden `.htaccess` files are included.

Git intentionally excludes everything under `uploads/`, so the database export does not carry uploaded files. To keep migrated product images, copy the files from the local `uploads/products/` directory into `htdocs/uploads/products/` on InfinityFree, preserving their generated filenames and the `/uploads/products/...` paths stored in the database. If a product image was not copied or its path no longer exists, the inventory and shop show the default product image; re-upload it from the hosted Admin inventory page to replace it. Student profile photos are personal data and are not included in this transfer by default. Do not copy payment proofs into the public product-image directory; transfer them separately and keep them under `uploads/payment-proofs/`.

Use the hosting panel's normal permissions. Do not set directories to world-writable (`777`). The `uploads/` directory must be writable by PHP for image and payment-proof uploads. Keep the repository versions of `uploads/.htaccess` and `uploads/products/.htaccess`: they prevent directory listing and deny script extensions. Avoid adding `Options -ExecCGI` to nested upload `.htaccess` files because shared hosts may reject it with HTTP 500 responses.

## 6. Production verification

1. Open the HTTPS domain and confirm HTTP redirects to HTTPS.
2. Confirm direct requests to `.env`, `composer.json`, `.sql` files, `config/`, `includes/`, `database/`, and `vendor/` are denied.
3. Confirm the application loads and the host's PHP/MySQL logs contain no configuration or migration errors.
4. Configure and verify SMTP delivery before enabling student registrations that require email verification.
5. Create or verify the production administrator using the hosting provider's supported secure method, then change any temporary password immediately.
6. Exercise registration, email verification, login/logout, session persistence, password reset, student/admin authorization, product listing and management, cart, checkout, payment-proof upload, order creation/history/status, QR claiming, receipts, and notifications on the hosted HTTPS site.
7. Test rejection of invalid CSRF tokens, unauthorized admin/student requests, disallowed uploads, and requests to protected files and directories.
8. Check error logs after each test and verify visitor responses never contain SQL, credentials, server paths, or stack traces.

## Project layout

- `auth/`, `student/`, `admin/`, and `api/`: authentication, customer/admin pages, and endpoints.
- `config/` and `includes/`: runtime configuration, shared authentication, database, migrations, and workflows; direct web access is denied.
- `database/`: CLI-only utilities and the local data export; direct web access is denied.
- `assets/`: public static files.
- `uploads/`: user-generated files with PHP/script execution denied.
- `vendor/`: Composer dependencies; direct web access is denied.
