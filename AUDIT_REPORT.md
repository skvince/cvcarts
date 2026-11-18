# CVCarts Complete Audit Report

## Audit scope

- Inspected the 48 application PHP files and supporting assets/configuration.
- Inspected project configuration, routes, database entry points, uploads, authentication, authorization, CSRF, mail, and realtime endpoints.
- Checked all PHP source files with `php -l`; all passed.
- Inspected the live `cscqc_ecommerce` MySQL database and refreshed `database/cscqc_ecommerce.sql`.
- Created a timestamped backup before changes.

## Initial findings

1. `.env` contained a live-looking SMTP app password and a fixed local base URL. The SMTP password was removed and the application key was replaced with a new random key.
2. The application could still run database setup code after a browser database connection failure. Browser setup is now disabled.
3. `APP_BASE_URL` assumed localhost or a fixed subdirectory. The base URL now derives from the HTTPS host and project directory unless explicitly configured.
4. Session cookies used the root path. They now use the application base path.
5. Database and setup configuration were environment-based but the setup script was directly reachable over HTTP. HTTP access is now blocked.
6. Root `.htaccess` blocked sensitive files but did not provide HTTPS, security headers, or broader vendor/directory protection. Those controls were added.
7. SMTP validation was hardcoded to one Gmail account. It now supports a configured SMTP provider/account.
8. Production error display was not centrally controlled. `APP_ENV=production` now disables displayed PHP errors and startup errors.

## Security and functionality review

- Authentication uses password hashing, session regeneration, account verification/active checks, auth-version invalidation, and failed-attempt rate limiting.
- CSRF tokens are required for state-changing POST forms and the JSON QR claim endpoint.
- Database writes found in the application use PDO prepared statements for parameters.
- Output displayed from database/user input is generally escaped with `cvcarts_h()`.
- Payment proof access verifies login and ownership/admin role, validates filenames and MIME types, and prevents directory traversal.
- Product, profile, and payment uploads validate file size and detected MIME type.
- Protected directories use Apache access rules where supported.
- PHP syntax validation passed for every application PHP file.

## Database

The live database contains 18 application tables, including users, products, carts, orders, order items, payments, schedules, notifications, and audit/support tables. Foreign keys and indexes are present for the implemented relationships. The database export at `database/cscqc_ecommerce.sql` was refreshed from the live database and includes current application data and schema. It must be reviewed for intended data before any production import.

## Fixes applied

- Removed SMTP app password from `.env`.
- Replaced `APP_KEY` with a new 64-character random secret.
- Changed the local environment to derive the base URL automatically.
- Added production error handling.
- Blocked database/setup scripts over HTTP.
- Removed browser-triggered database setup fallback.
- Added dynamic session cookie paths.
- Added HTTPS redirect and security headers to root `.htaccess`.
- Made SMTP provider configuration generic.
- Updated Docker Composer installation and PHP setup.
- Updated database export.
- Reversed setup-only catalog seed rows added during verification, preserving the existing live catalog.

## SQL import compatibility fix

- Removed mysqldump-specific conditional comments and hidden BOM characters from `database/cscqc_ecommerce.sql`.
- Added plain `SET NAMES`, foreign-key, and unique-check statements.
- Tested the export by importing all 18 application tables into a temporary MySQL database; the import passed.

## Remaining manual work

- Set `APP_ENV=production`, `APP_BASE_URL=https://...`, real DB credentials, SMTP credentials, and a valid `ADMIN_EMAIL` on the live host.
- Obtain or recreate a real SMTP account/app password and verify email delivery.
- Use a valid TLS certificate and verify HSTS/redirect behavior.
- Import the SQL backup only after confirming that existing records are intended for the production host.
- Create or verify the production administrator using `php database/init_admin.php`.
- Test login/logout, CRUD operations, payments, uploads, receipt print, realtime updates, QR scanning, and role restrictions in a browser with HTTPS.
- Confirm Apache modules `rewrite` and `headers` are enabled, or apply equivalent hosting control-panel rules.
- Confirm payment-proof and user-upload folder permissions permit only the web server to write and do not expose executable files.
