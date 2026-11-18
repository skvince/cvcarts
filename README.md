# CVCarts Developer Guide

This guide documents the PHP/MySQL application that is currently in this project folder. It is intended as a practical reference for local setup, manual changes, database work, and troubleshooting.

> **Secrets:** `.env` contains local configuration and must stay private. This guide deliberately does not reproduce its values. Never paste passwords, app keys, SMTP credentials, reset links, or live user data into documentation or logs.

## 1. Project overview

| Topic | What is in this project |
|---|---|
| Purpose | Campus e-commerce storefront for CSCQC students, with an Admin panel for catalog, inventory, order/payment review, QR claiming, and order history. |
| Backend | PHP pages and endpoints using PDO with MySQL prepared statements. |
| Frontend | Server-rendered HTML with Bootstrap 5, Bootstrap Icons, per-page custom CSS, and shared native alert/confirmation dialogs in `assets/js/modal.js`. |
| Database | MySQL database configured by environment variables. The default database name in `.env.example` is `cscqc_ecommerce`. |
| Email | PHPMailer over configured SMTP. `includes/mailer.php` centralizes SMTP, verification, password reset, OTP, and delivery logging. |
| Roles | `admin` manages the shop and operations; `student` browses, orders, submits online payment proof, and views their own orders/receipts. |
| External resources | Google Fonts, Bootstrap 5 and Bootstrap Icons CSS, QR rendering/scanning JavaScript CDNs, SMTP provider, and externally hosted product image URLs may require an internet connection. |

This application does **not** integrate a payment gateway. Online payments are submitted with a reference and proof for Admin review; cash is marked paid by Admin after collection. The code creates an order and payment records in the local database.

## 2. Local setup and safe maintenance

1. Install XAMPP with PHP 8.0 or newer, MySQL, and PDO MySQL. The application uses PHP 8 features such as `str_ends_with()` and `match`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. From the project root, run `composer install` if Composer dependencies are missing. The `vendor/` folder currently contains PHPMailer.
4. Copy `.env.example` to `.env` only if a local `.env` is not already present. Generate a unique `APP_KEY` for local or production use, set database settings, set `ADMIN_EMAIL` to the primary administrator address, and configure `APP_BASE_URL`; use the deployed HTTPS origin for production. Set SMTP credentials privately in `.env`, never in source control.
5. From the project root, run `php database/setup.php` to create the configured database/base tables, run migrations, and seed development catalog items where absent.
6. Initialize the administrator once with `php database/init_admin.php`. The command creates an administrator only when `ADMIN_EMAIL` is unused, generates a random temporary password, and displays it once in the CLI. It will not reset an existing account's password. Change the temporary password after signing in.
7. Open the configured `APP_BASE_URL` in a browser (the example uses `http://localhost/Cvcarts`). Production requires a publicly reachable host, HTTPS, and one shared remote MySQL database.

`database/setup.php` is **not read-only**: it creates/updates schema and can seed sample catalog products. It is CLI-only and no longer creates a default administrator or assigns a known password. `config/database.php` does not perform schema setup for browser requests. Back up the database before manual schema or migration work.

The shared Admin and Student navigation uses Server-Sent Events (SSE) for cross-device change signals. The stream endpoint is `/api/realtime/stream.php`; `realtime_events` records events in the same database transaction as supported order, payment, catalog, and inventory changes. Student event delivery is limited to public catalog updates and events addressed to that student. Keep PHP output buffering disabled for the SSE route (the response sends `X-Accel-Buffering: no`) and allow long-lived HTTP requests on the host. HTTPS automatically secures SSE in production; no separate WebSocket service is used.

### SMTP preflight

`includes/mailer.php` contains the connection-only preflight function. It uses the `.env` values loaded by `config/app.php`. From the project root, the following PHP command tests SMTP connectivity/authentication without sending a message:

```sh
php -r "require 'config/app.php'; require 'includes/mailer.php'; print_r(cvcarts_test_smtp_connection());"
```

An SMTP preflight is not proof that a recipient received a message. `cvcarts_send_mail()` records the mailer's accepted/failed result in `email_delivery_log` and logs sanitized error details via PHP's error log. A mail server accepting a message also does not guarantee delivery to the recipient inbox.

## 3. Application structure

### Complete project tree

The tree below reflects files and folders present when this guide was prepared. `.env` is shown as a local configuration file, not as content to copy or commit.

```text
Cvcarts/
├── .env
├── .env.example
├── .gitignore
├── .htaccess
├── README.md
├── composer.json
├── composer.lock
├── index.php
├── admin/
│   ├── dashboard.php
│   ├── inventory/
│   │   └── index.php
│   ├── order-history/
│   │   └── index.php
│   ├── order-status/
│   │   └── index.php
│   ├── ready-to-claim/
│   │   └── index.php
│   ├── qr-scanner/
│   │   └── index.php
│   ├── receipt.php
│   ├── sales/
│   │   └── index.php
│   ├── schedule/
│   │   └── index.php
│   ├── settings/
│   │   └── index.php
│   ├── shop/
│   │   └── index.php
│   └── users/
│       └── index.php
├── api/
│   ├── admin/
│   │   └── sales_series.php
│   ├── payments/
│   │   └── proof.php
│   ├── realtime/
│   │   ├── state.php
│   │   └── stream.php
│   └── qr/
│       └── claim.php
├── assets/
│   ├── css/
│   │   ├── admin/
│   │   │   ├── dashboard.css
│   │   │   ├── inventory.css
│   │   │   ├── order-details-modal.css
│   │   │   ├── order-history.css
│   │   │   ├── order-status.css
│   │   │   ├── ready-to-claim.css
│   │   │   ├── qr-scanner.css
│   │   │   ├── receipt.css
│   │   │   ├── sales.css
│   │   │   ├── schedule.css
│   │   │   ├── settings.css
│   │   │   └── users.css
│   │   └── auth/
│   │       ├── forgot-password.css
│   │       ├── reset-password.css
│   │       ├── signin.css
│   │       ├── signup.css
│   │       └── verification.css
│   │   ├── shared/
│   │   │   └── shared.css
│   │   └── student/
│   │       ├── cart.css
│   │       ├── checkout.css
│   │       ├── dashboard.css
│   │       ├── notifications.css
│   │       ├── order-history.css
│   │       ├── order-status.css
│   │       ├── receipt.css
│   │       ├── settings.css
│   │       └── shop.css
│   ├── images/
│   │   └── logo.png
│   └── js/
│       └── modal.js
├── auth/
│   ├── forgot-password.php
│   ├── login.php
│   ├── logout.php
│   ├── register.php
│   ├── registration-success.php
│   ├── reset-password.php
│   ├── verification-pending.php
│   └── verify-email.php
├── config/
│   ├── .htaccess
│   ├── app.php
│   └── database.php
├── database/
│   ├── .htaccess
│   ├── cscqc_ecommerce.sql
│   ├── init_admin.php
│   └── setup.php
├── includes/
│   ├── .htaccess
│   ├── auth.php
│   ├── functions.php
│   ├── mailer.php
│   ├── migrations.php
│   ├── order_workflows.php
│   └── pages/
│       ├── admin_chrome.php
│       ├── orders.php
│       ├── receipt.php
│       └── settings.php
├── student/
│   ├── cart/
│   │   └── index.php
│   ├── checkout/
│   │   └── index.php
│   ├── dashboard.php
│   ├── notifications/
│   │   └── index.php
│   ├── order-history/
│   │   └── index.php
│   ├── order-status/
│   │   └── index.php
│   ├── receipt.php
│   ├── settings/
│   │   └── index.php
│   └── shop/
│       └── index.php
├── uploads/
│   ├── .htaccess
│   └── payment-proofs/
│       └── .htaccess
└── vendor/
    ├── .htaccess
    ├── autoload.php
    ├── composer/
    │   ├── autoload_classmap.php
    │   ├── autoload_namespaces.php
    │   ├── autoload_psr4.php
    │   ├── autoload_real.php
    │   ├── autoload_static.php
    │   ├── ClassLoader.php
    │   ├── installed.json
    │   ├── installed.php
    │   ├── InstalledVersions.php
    │   ├── LICENSE
    │   └── platform_check.php
    └── phpmailer/
        └── phpmailer/
            ├── COMMITMENT
            ├── composer.json
            ├── get_oauth_token.php
            ├── LICENSE
            ├── README.md
            ├── SECURITY.md
            ├── SMTPUTF8.md
            ├── VERSION
            ├── language/
            │   ├── phpmailer.lang-af.php
            │   ├── phpmailer.lang-ar.php
            │   ├── phpmailer.lang-as.php
            │   ├── phpmailer.lang-az.php
            │   ├── phpmailer.lang-ba.php
            │   ├── phpmailer.lang-be.php
            │   ├── phpmailer.lang-bg.php
            │   ├── phpmailer.lang-bn.php
            │   ├── phpmailer.lang-ca.php
            │   ├── phpmailer.lang-cs.php
            │   ├── phpmailer.lang-da.php
            │   ├── phpmailer.lang-de.php
            │   ├── phpmailer.lang-el.php
            │   ├── phpmailer.lang-eo.php
            │   ├── phpmailer.lang-es.php
            │   ├── phpmailer.lang-et.php
            │   ├── phpmailer.lang-fa.php
            │   ├── phpmailer.lang-fi.php
            │   ├── phpmailer.lang-fo.php
            │   ├── phpmailer.lang-fr.php
            │   ├── phpmailer.lang-gl.php
            │   ├── phpmailer.lang-he.php
            │   ├── phpmailer.lang-hi.php
            │   ├── phpmailer.lang-hr.php
            │   ├── phpmailer.lang-hu.php
            │   ├── phpmailer.lang-hy.php
            │   ├── phpmailer.lang-id.php
            │   ├── phpmailer.lang-it.php
            │   ├── phpmailer.lang-ja.php
            │   ├── phpmailer.lang-ka.php
            │   ├── phpmailer.lang-ko.php
            │   ├── phpmailer.lang-ku.php
            │   ├── phpmailer.lang-lt.php
            │   ├── phpmailer.lang-lv.php
            │   ├── phpmailer.lang-mg.php
            │   ├── phpmailer.lang-mn.php
            │   ├── phpmailer.lang-ms.php
            │   ├── phpmailer.lang-nb.php
            │   ├── phpmailer.lang-nl.php
            │   ├── phpmailer.lang-pl.php
            │   ├── phpmailer.lang-pt.php
            │   ├── phpmailer.lang-pt_br.php
            │   ├── phpmailer.lang-ro.php
            │   ├── phpmailer.lang-ru.php
            │   ├── phpmailer.lang-si.php
            │   ├── phpmailer.lang-sk.php
            │   ├── phpmailer.lang-sl.php
            │   ├── phpmailer.lang-sr.php
            │   ├── phpmailer.lang-sr_latn.php
            │   ├── phpmailer.lang-sv.php
            │   ├── phpmailer.lang-tl.php
            │   ├── phpmailer.lang-tr.php
            │   ├── phpmailer.lang-uk.php
            │   ├── phpmailer.lang-ur.php
            │   ├── phpmailer.lang-vi.php
            │   ├── phpmailer.lang-zh.php
            │   └── phpmailer.lang-zh_cn.php
            └── src/
                ├── DSNConfigurator.php
                ├── Exception.php
                ├── OAuth.php
                ├── OAuthTokenProvider.php
                ├── PHPMailer.php
                ├── POP3.php
                └── SMTP.php
```

### Folder responsibilities

| Folder | Contents and connections |
|---|---|
| `admin/` | Admin route root; contains dashboard and nested modules below. Pages enforce Admin access and connect to shared logic in `includes/`. |
| `admin/inventory/` | Inventory route. Product CRUD, image handling, stock/size changes, archiving, and inventory filters live here. |
| `admin/order-history/` | Historical-order route wrapper; sets history mode and includes the shared orders page. |
| `admin/order-status/` | Active-order route wrapper; sets active mode and includes the shared orders page. |
| `admin/qr-scanner/` | Camera/manual scanner UI; sends requests to `api/qr/claim.php`. |
| `admin/sales/` | Paid-sales report and CSV export route. |
| `admin/ready-to-claim/` | Paid and verified orders awaiting physical handover; includes order details, QR code, filters, and scanner access. |
| `admin/schedule/` | Legacy URL that redirects to Ready to Claim; pickup-slot management is no longer available. |
| `admin/settings/` | Admin-only entry point for shared profile/password/recovery settings. |
| `admin/shop/` | Compatibility route that redirects Admins to Inventory. |
| `admin/users/` | Student account listing, search/filter, activation, and suspension. |
| `api/` | Authenticated backend endpoints used by page forms or JavaScript requests. |
| `api/admin/` | Admin-only JSON endpoint that returns the dashboard sales chart series for the selected period. |
| `api/payments/` | Private payment-proof serving endpoint. |
| `api/qr/` | Server-side QR verification and order-claim endpoint. |
| `assets/` | Static stylesheets, scripts, and brand image used by rendered pages. |
| `assets/css/` | Shared Admin/Student styles and authentication styles. |
| `assets/css/auth/` | Dedicated CSS files for login, signup/verification, and recovery screens. |
| `assets/images/` | Shared logo image referenced by auth, panel, and receipt pages. |
| `assets/js/` | Shared JavaScript behavior; other page-specific scripts are inline in PHP. |
| `auth/` | Login, registration, email verification, logout, and account recovery routes. |
| `config/` | Environment loading and PDO connection setup. Its `.htaccess` blocks direct web access. |
| `database/` | Schema-only dump and database bootstrap. Setup invokes `includes/migrations.php`. |
| `includes/` | Shared auth/session setup, helpers, mailer, migrations, order/payment workflows, and an access-denial `.htaccess`. |
| `includes/pages/` | Shared Admin/Student page implementations (Admin chrome, orders, receipts, settings) included by role-specific route wrappers. |
| `includes/pages/admin_chrome.php` | Shared Admin header, notification/profile menus, and sidebar used by every Admin page. |
| `student/` | Student route root; contains dashboards and nested shopping/account modules below. |
| `student/cart/` | Student-owned cart editing and removal. |
| `student/checkout/` | Checkout, payment validation, and transactional order creation; no pickup slot is assigned. |
| `student/notifications/` | Student notification list and read-state actions. |
| `student/order-history/` | Historical-order wrapper for the shared orders implementation. |
| `student/order-status/` | Active-order wrapper for the shared orders implementation. |
| `student/settings/` | Student-only entry point for the shared settings implementation. |
| `student/shop/` | Product search and Buy Now/Add to Cart interface. |
| `uploads/` | Upload root with rules to disable script execution. Product image storage is created under `uploads/products/` on demand; it was not present in the inspected tree. |
| `uploads/payment-proofs/` | Private payment-proof directory; its `.htaccess` denies direct browser access. |
| `vendor/` | Composer autoloader and PHPMailer third-party dependency. Do not edit to change application behavior. |
| `vendor/composer/` | Composer-generated class maps, loader, and installed-package metadata; Composer can regenerate these. |
| `vendor/phpmailer/` | Namespace/vendor directory containing the PHPMailer package. |
| `vendor/phpmailer/phpmailer/` | PHPMailer library root, upstream metadata/docs, OAuth example, locale files, and source classes. |
| `vendor/phpmailer/phpmailer/language/` | PHPMailer diagnostic-message translations; each locale PHP file returns a language catalog. |
| `vendor/phpmailer/phpmailer/src/` | PHPMailer transport, message, exception, OAuth, and POP3 implementations used or packaged by the mail library. |

## 4. File-by-file reference

### Root and application configuration

| File | Purpose, connections, and what to edit |
|---|---|
| `.env` | Private, local environment values loaded by `config/app.php`. Contains application URL/key, database, and SMTP settings. Edit local settings here only; never paste its contents into tickets or commit it. |
| `.env.example` | Safe starter template with the variable names expected by the app and a blank SMTP password. Copy it only to initialize a missing local `.env`; replace the example `APP_KEY` with a unique secret. |
| `.gitignore` | Excludes `.env`, storage logs, email logs, and payment-proof uploads from Git. Extend it for any new private runtime data. |
| `.htaccess` | Disables directory indexes and blocks web requests for `.env` files, Composer manifests, and `.sql` files. Requires Apache 2.4-style `Require all denied`. |
| `README.md` | This developer guide. Keep folder/file descriptions and operational notes synchronized with code changes. |
| `composer.json` | Declares the application package and PHPMailer dependency (`phpmailer/phpmailer`). Change dependencies here, then run Composer to update `composer.lock` and `vendor/`. |
| `composer.lock` | Pins the installed dependency version (the current installed PHPMailer metadata reports v6.12.0). Normally updated by Composer, not edited by hand. |
| `index.php` | Entry router: loads shared auth/session code, then sends a signed-in user to the role's dashboard or an unsigned user to login. |
| `config/app.php` | Parses the root `.env` with `parse_ini_file`, populates environment variables not already set by the process, and defines `CVCARTS_BASE_URL`, `CVCARTS_BASE_PATH`, and `CVCARTS_ADMIN_EMAIL`. Change URL/environment loading here only when changing the configuration model. |
| `config/database.php` | Reads `DB_*`, creates PDO for MySQL with exceptions, associative fetches, and native prepares. On an initial connection exception, it attempts `database/setup.php` and reconnects. `require_database_connection()` returns a 503 setup message when no connection is available. |
| `config/.htaccess` | Denies direct browser access to the config folder. |

### Admin pages

| File | Purpose, connections, and what to edit |
|---|---|
| `admin/dashboard.php` | Admin overview: KPI counters, paid sales, recent payment/claiming activity, low-stock reminders, and a sales chart with a live monthly/weekly/yearly period selector backed by `api/admin/sales_series.php`. Uses the shared Admin header (notification bell and profile menu) and sidebar from `includes/pages/admin_chrome.php`. Queries `users`, `products`, `orders`, `order_items`, and `admin_activity`; uses `assets/css/admin/dashboard.css` with `assets/css/shared/shared.css`. |
| `admin/inventory/index.php` | Admin product CRUD, image upload, standard and per-size stock, stock movement audit, permanent delete (with uploaded-image cleanup), filtering, sorting, and pagination. Uses product and inventory helpers in `includes/functions.php`; styles come from `assets/css/admin/inventory.css`; modal behavior from `assets/js/modal.js`. |
| `admin/order-history/index.php` | Admin route wrapper selecting Claimed and Cancelled orders in server-filtered, view-only mode; displays actual received/cancellation dates and supports order/payment/date filters. |
| `admin/order-status/index.php` | Admin route wrapper for pending payment review and eligible cancellation; successful payment review transfers orders directly to Ready to Claim. |
| `admin/ready-to-claim/index.php` | Admin route wrapper selecting only Paid, verified, unclaimed Ready to Claim orders. Provides item/student details, QR status and display, filters, and scanner access. |
| `admin/qr-scanner/index.php` | Admin-only camera/manual scanner. Displays verified order details and requires explicit handover confirmation before claiming; requests go to `api/qr/claim.php`. Camera access needs localhost or HTTPS and browser permission. |
| `admin/receipt.php` | Admin-only receipt route wrapper; checks role then includes `includes/pages/receipt.php`. |
| `admin/sales/index.php` | Paid, non-cancelled revenue summary by period/payment method and recent transaction CSV export. Reads `orders` and `users`; uses `assets/css/admin/sales.css` with the shared panel stylesheet. |
| `admin/schedule/index.php` | Compatibility redirect to Ready to Claim. Existing schedule records remain in the database but are not used in checkout or claiming. |
| `admin/settings/index.php` | Admin-only wrapper for the shared `includes/pages/settings.php` profile, password, and account-recovery forms. |
| `admin/shop/index.php` | Legacy/compatibility route; after Admin authorization it redirects to `admin/inventory/index.php`. |
| `admin/users/index.php` | Admin student list with search/status/date/sort/page filters; activates or suspends accounts while preserving verification requirements. Writes Admin audit activity. |

### API endpoints

| File | Purpose, connections, and what to edit |
|---|---|
| `api/admin/sales_series.php` | Admin-only JSON GET endpoint. Returns zero-filled weekly/monthly/yearly paid-sales series (`period`, `labels`, `values`, `total`) for the dashboard chart. |
| `api/payments/proof.php` | Streams the latest payment proof only to an Admin or the owning Student. Validates stored filename, real path, and MIME type; reads `payments` and files under `uploads/payment-proofs/`. |
| `api/qr/claim.php` | JSON POST endpoint for Admin-only QR verification and claiming. Validates session, role, CSRF header, and token; loads order/student/items; checks payment/order state; uses a transaction and conditional update to prevent duplicate claims; records history, notification, and Admin activity. |

### Authentication pages

| File | Purpose, connections, and what to edit |
|---|---|
| `auth/forgot-password.php` | Student/Admin recovery entry point. Sends a one-use reset link via shared mailer for eligible accounts; includes Admin security-question and emailed OTP recovery flow; uses `account_tokens` and `auth_attempts`. |
| `auth/login.php` | Accepts Student email or Admin username/email; checks password hash, verification, active state, and rate limits; rotates the session ID and redirects by role. |
| `auth/logout.php` | Clears and destroys the session, then redirects to login. |
| `auth/register.php` | Validates Student details and `@cscqc.edu.ph` email, hashes password/security answer, inserts an unverified Student and 15-minute verification token, then calls the shared verification mailer. |
| `auth/registration-success.php` | Session-gated success screen shown after a valid verification link is used. Uses `assets/css/auth/signup.css`. |
| `auth/reset-password.php` | Validates and consumes Admin/Student password-reset tokens, saves a strong new password, increments `auth_version` to invalidate existing sessions, and writes Admin recovery activity when applicable. |
| `auth/verification-pending.php` | Loads the pending Student from the session and handles resend requests. Applies resend throttling, issues a replacement 15-minute token, sends via `includes/mailer.php`, and displays success/failure. |
| `auth/verify-email.php` | Hashes and validates the presented token against `account_tokens`; checks expiry/account state; activates the Student and consumes current/other verification tokens in a transaction. Expired tokens route back to resend. |

### Student pages

| File | Purpose, connections, and what to edit |
|---|---|
| `student/cart/index.php` | Displays the signed-in Student's cart, validates item ownership, updates quantity/size within available stock, merges duplicate product/size combinations safely, and removes items. |
| `student/checkout/index.php` | Checks out cart or a separate session-based Buy Now item; validates stock, price, and payment method under transaction/row locks; creates order/payment/items/history/notification and deducts stock. Cash requires no proof; online payment requires reference and proof. |
| `student/dashboard.php` | Student summary of own orders, cart, notifications, payment/claim counts, recent products, and notifications. |
| `student/notifications/index.php` | Lists only the current Student's notifications and allows marking one/all read. |
| `student/order-history/index.php` | Student historical-order wrapper for shared `includes/pages/orders.php`; the shared handler restricts queries/actions to that Student. |
| `student/order-status/index.php` | Student active-order wrapper for shared `includes/pages/orders.php`; the shared handler restricts queries/actions to that Student. |
| `student/receipt.php` | Student-only receipt route wrapper; shared receipt code enforces order ownership. |
| `student/settings/index.php` | Student-only wrapper for shared profile/password/recovery settings page. |
| `student/shop/index.php` | Product search/filter/sort/pagination, size/stock selection, Add to Cart and independent Buy Now actions. Pulls active products and available sizes; product detail dialogs use shared modal JS. |

### Shared code and database files

| File | Purpose, important functions, connections, and what to edit |
|---|---|
| `includes/.htaccess` | Denies direct web requests into shared PHP internals. |
| `includes/auth.php` | Configures secure session cookie options, starts the session, loads helper functions, and initializes flash-message storage. |
| `includes/functions.php` | Shared PDO/migration entry (`cvcarts_db`), URLs/redirects, CSRF, flash messages, password/rate-limit helpers, token storage, escaping/money, uploads, session user/role guards, QR token generation, inventory helpers, and transaction-scoped real-time event insertion. |
| `includes/mailer.php` | Central SMTP configuration/preflight and all outbound mail. Key functions: `cvcarts_smtp_config`, `cvcarts_smtp_configuration_error`, `cvcarts_test_smtp_connection`, `cvcarts_send_mail`, and the verification/reset/OTP template functions. SMTP errors are sanitized before logging; delivery results are written to `email_delivery_log`. |
| `includes/migrations.php` | Idempotent schema upgrades protected by MySQL named locks. Adds workflow, profile, and `realtime_events` schema without resetting existing application records; disables the known legacy seeded development Admin account. |
| `includes/order_workflows.php` | Shared notifications, claim-state validation, transactional Admin payment approvals, QR issuance, order status and cancellation transitions, inventory restoration, and audit history. |
| `includes/pages/orders.php` | Shared Admin/Student order listing and detail UI, payment proof/re-submission, mode-specific filters and pagination, QR display, and read-only archived orders. |
| `includes/pages/receipt.php` | Shared printable payment/claiming receipt with the fixed role-based navigation and live order status/QR refresh. Enforces role/ownership and renders the same persisted QR token on paid student receipts. |
| `includes/pages/settings.php` | Shared profile, password, and security-recovery form handler/UI. Admin and Student wrappers choose the role-specific routes; Admin changes are audited. |
| `database/.htaccess` | Denies direct browser access to database setup and dump files. |
| `database/cscqc_ecommerce.sql` | Schema-only SQL import containing table definitions, indexes, and foreign keys (not local customer/order records). Run `database/setup.php` after importing to apply migrations and development seed behavior. |
| `database/setup.php` | Creates the configured database/base tables, invokes migrations, and inserts sample products if missing. It does not create an Admin account. It changes persistent data; inspect and back up before using on an existing environment. |
| `database/init_admin.php` | CLI-only one-time Admin initializer. Reads `ADMIN_EMAIL`, creates an account only if that email is unused, hashes a cryptographically random temporary password, and never resets an existing account's password. |
| `api/realtime/stream.php` | Authenticated SSE stream. Admins receive admin/public events; Students receive public catalog events and events addressed to their own user ID. Event payloads contain event types only. |
| `api/realtime/state.php` | Authenticated, role-scoped notification/cart counters used by the shared header and navigation. |

### Access-control files

| File | Purpose and connection |
|---|---|
| `config/.htaccess` | Denies direct browser requests to environment and database configuration files. |
| `database/.htaccess` | Denies direct browser requests to the setup script and SQL schema dump. |
| `includes/.htaccess` | Denies direct browser requests to shared PHP internals that are meant to be included by route files. |
| `uploads/.htaccess` | Disables directory listing and CGI handlers, treats common script extensions as plain text, and denies access to executable uploads. |
| `uploads/payment-proofs/.htaccess` | Denies direct access to payment proof files; authorized access goes through `api/payments/proof.php`. |

### Styles, scripts, and image

| File | Purpose, connections, and what to edit |
|---|---|
| `assets/css/shared/shared.css` | Common Admin/Student theme, sticky header, fixed desktop sidebar, responsive mobile drawer/backdrop, persistent header logout, SSE status indicator, panels, buttons, forms, tables, statuses, native dialog/backdrop, and responsive rules. |
| `assets/css/admin/dashboard.css` | Admin dashboard cards, statistics, chart, and dashboard grid. Key selectors: `.stats-grid`, `.dashboard-grid`, `.sales-widget-controls`, `.select-small`, `.sales-widget-total`. |
| `assets/css/admin/users.css` | Admin user listing table and toolbar. Key selectors: `.users-table`, `.users-toolbar`. |
| `assets/css/admin/schedule.css` | Legacy schedule styles retained but no longer loaded; the old Schedule route redirects to Ready to Claim. |
| `assets/css/admin/order-status.css` | Admin order status page controls and order detail tables. Loaded by the shared order page only for Admin Order Status. |
| `assets/css/admin/ready-to-claim.css` | Ready to Claim table, responsive layout, and QR details presentation. |
| `assets/css/admin/order-history.css` | Admin order-history table and detail presentation. Loaded only for Admin Order History. |
| `assets/css/admin/order-details-modal.css` | Shared Admin order detail modal internals, including the QR claim token and student email display. |
| `assets/css/admin/qr-scanner.css` | Admin scanner layout and claim result panel. Key selectors: `.claim-grid`, `.scanner-panel #reader`, `.scanner-controls`. |
| `assets/css/admin/inventory.css` | Inventory-only theme, product toolbar/table, stock indicators, and dialogs. It is the only stylesheet for Inventory beyond Bootstrap/Icons; update this file for Inventory visuals. |
| `assets/css/admin/sales.css` | Sales summary cards, chart grid, and transaction table. Key selectors: `.sales-stats`, `.sales-grid`, `.sales-transactions`. |
| `assets/css/admin/settings.css` | Admin settings page section sizing and recovery/security-help spacing. |
| `assets/css/admin/receipt.css` | Admin receipt print layout and QR display. Receipt output is rendered by `includes/pages/receipt.php`. |
| `assets/css/student/dashboard.css` | Student dashboard statistics and summary grid. Key selectors: `.student-stats`, `.student-dashboard-grid`. |
| `assets/css/student/cart.css` | Student cart table, quantity field, totals, and action layout. Key selectors: `.cart-table`, `.cart-total`, `.cart-actions`. |
| `assets/css/student/shop.css` | Student product cards/grid and product order form. Key selectors: `.product-grid`, `.product-card`, `.product-order-form`. |
| `assets/css/student/checkout.css` | Student checkout summary and payment layout. Key selectors: `.checkout-grid`, `.checkout-summary`, `.checkout-submit`. |
| `assets/css/student/order-status.css` | Student order status detail, payment resubmission, and QR presentation. |
| `assets/css/student/order-history.css` | Student order-history table and receipt/QR details. Loaded only for Student Order History. |
| `assets/css/student/notifications.css` | Student reminder/notification table and note panel. Key selectors: `.reminder-table`, `.reminder-type`, `.reminder-note`. |
| `assets/css/student/settings.css` | Student settings page section sizing and recovery/security-help spacing. |
| `assets/css/student/receipt.css` | Student receipt print layout and QR display. Receipt output is rendered by `includes/pages/receipt.php`. |
| `assets/css/auth/signin.css` | Sign-in page-specific layout, password visibility control, and responsive rules; loaded by `auth/login.php`. |
| `assets/css/auth/signup.css` | Sign-up page form fields, validation alerts, and responsive layout; loaded by `auth/register.php`. |
| `assets/css/auth/forgot-password.css` | Account recovery form, email/security-question fields, and validation message design; loaded by `auth/forgot-password.php`. |
| `assets/css/auth/reset-password.css` | Reset-password-only alert overrides; imports the recovery page's shared visual base and is loaded by `auth/reset-password.php`. |
| `assets/css/auth/verification.css` | Verification-pending and registration-success screen styling; imports the sign-up visual base and is loaded by those two routes. |
| `assets/images/logo.png` | Brand logo displayed in authentication, panel headers, and receipts. Replace this asset to change the shared logo without changing page logic. |
| `assets/js/modal.js` | Shared native `<dialog>` alerts and confirmations, responsive navigation drawer controls, SSE reconnect/status handling, role-scoped counters, safe page-fragment synchronization for supported pages, Escape/backdrop behavior, form confirmation, and duplicate-submit blocking. |

Operational pages load Bootstrap 5 first, then Bootstrap Icons, then the common panel stylesheet (where used), and finally their dedicated page stylesheet. Authentication pages load Bootstrap/Icons before their own authentication stylesheet. Inventory has its own complete layout stylesheet and loads Bootstrap/Icons before it. Bootstrap's JavaScript is not used for the application's native `<dialog>` modals; those continue to use `assets/js/modal.js`.

The former legacy `assets/css/admin.css` has been removed. Admin pages now use `assets/css/shared/shared.css` plus their own `assets/css/admin/*.css` file; do not reintroduce a global Admin stylesheet.

### Composer and PHPMailer files

These are dependency/generated files, listed to make the tree complete. Do not change them to customize CVCarts; change `composer.json` and use Composer instead. The `.htaccess` files block direct web requests into these directories.

| File(s) | What each file does |
|---|---|
| `vendor/.htaccess` | Denies direct browser access to the vendor directory. |
| `vendor/autoload.php` | Composer entry point loaded by the application to find PHPMailer classes. |
| `vendor/composer/autoload_classmap.php` | Generated class-to-file map. |
| `vendor/composer/autoload_namespaces.php` | Generated legacy namespace map. |
| `vendor/composer/autoload_psr4.php` | Generated PSR-4 namespace map, including PHPMailer. |
| `vendor/composer/autoload_real.php` | Builds and initializes Composer's loader at runtime. |
| `vendor/composer/autoload_static.php` | Generated optimized maps used by the loader. |
| `vendor/composer/ClassLoader.php` | Composer's third-party class-loading implementation. |
| `vendor/composer/installed.json` | Composer package/version metadata in JSON. |
| `vendor/composer/installed.php` | Generated installed-package metadata in PHP form. |
| `vendor/composer/InstalledVersions.php` | Composer API for querying installed package versions. |
| `vendor/composer/LICENSE` | License notice for Composer's generated runtime support. |
| `vendor/composer/platform_check.php` | Composer-generated PHP/platform compatibility check. |
| `vendor/phpmailer/phpmailer/COMMITMENT` | PHPMailer project commitment document. |
| `vendor/phpmailer/phpmailer/composer.json` | PHPMailer package metadata, requirements, autoload rules, and upstream scripts. |
| `vendor/phpmailer/phpmailer/get_oauth_token.php` | PHPMailer's standalone OAuth-token example utility; not used by the CVCarts SMTP flow. |
| `vendor/phpmailer/phpmailer/LICENSE` | PHPMailer license text. |
| `vendor/phpmailer/phpmailer/README.md` | Upstream PHPMailer usage and configuration documentation. |
| `vendor/phpmailer/phpmailer/SECURITY.md` | Upstream security reporting guidance. |
| `vendor/phpmailer/phpmailer/SMTPUTF8.md` | Upstream notes about SMTPUTF8 support. |
| `vendor/phpmailer/phpmailer/VERSION` | Upstream PHPMailer version marker. |
| `vendor/phpmailer/phpmailer/src/DSNConfigurator.php` | Configures a PHPMailer instance from a DSN string; not directly called by CVCarts. |
| `vendor/phpmailer/phpmailer/src/Exception.php` | PHPMailer-specific exception class. |
| `vendor/phpmailer/phpmailer/src/OAuth.php` | OAuth token-provider implementation for SMTP auth; CVCarts currently uses username/password SMTP authentication. |
| `vendor/phpmailer/phpmailer/src/OAuthTokenProvider.php` | OAuth token-provider interface used by PHPMailer. |
| `vendor/phpmailer/phpmailer/src/PHPMailer.php` | Core message composition, addressing, MIME, and send implementation used by `includes/mailer.php`. |
| `vendor/phpmailer/phpmailer/src/POP3.php` | POP3 connection/authentication helper; not part of the current SMTP path. |
| `vendor/phpmailer/phpmailer/src/SMTP.php` | SMTP protocol transport, connection, TLS, authentication, and response handling used by PHPMailer. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-af.php` | Afrikaans PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ar.php` | Arabic PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-as.php` | Assamese PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-az.php` | Azerbaijani PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ba.php` | Bashkir PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-be.php` | Belarusian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-bg.php` | Bulgarian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-bn.php` | Bengali PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ca.php` | Catalan PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-cs.php` | Czech PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-da.php` | Danish PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-de.php` | German PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-el.php` | Greek PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-eo.php` | Esperanto PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-es.php` | Spanish PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-et.php` | Estonian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-fa.php` | Persian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-fi.php` | Finnish PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-fo.php` | Faroese PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-fr.php` | French PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-gl.php` | Galician PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-he.php` | Hebrew PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-hi.php` | Hindi PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-hr.php` | Croatian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-hu.php` | Hungarian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-hy.php` | Armenian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-id.php` | Indonesian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-it.php` | Italian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ja.php` | Japanese PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ka.php` | Georgian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ko.php` | Korean PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ku.php` | Kurdish PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-lt.php` | Lithuanian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-lv.php` | Latvian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-mg.php` | Malagasy PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-mn.php` | Mongolian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ms.php` | Malay PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-nb.php` | Norwegian Bokmål PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-nl.php` | Dutch PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-pl.php` | Polish PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-pt.php` | Portuguese PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-pt_br.php` | Brazilian Portuguese PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ro.php` | Romanian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ru.php` | Russian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-si.php` | Sinhala PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-sk.php` | Slovak PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-sl.php` | Slovenian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-sr.php` | Serbian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-sr_latn.php` | Serbian Latin PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-sv.php` | Swedish PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-tl.php` | Tagalog PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-tr.php` | Turkish PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-uk.php` | Ukrainian PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-ur.php` | Urdu PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-vi.php` | Vietnamese PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-zh.php` | Chinese PHPMailer error-message translations. |
| `vendor/phpmailer/phpmailer/language/phpmailer.lang-zh_cn.php` | Simplified Chinese PHPMailer error-message translations. |

## 5. How the main files connect

### Shared request setup

```text
Browser request
  -> route PHP page
  -> includes/auth.php (session + shared functions)
  -> config/app.php (.env + URL constants)
  -> config/database.php (PDO)
  -> includes/functions.php:cvcarts_db()
  -> includes/migrations.php (idempotent schema checks)
  -> role/CSRF checks
  -> page-specific database work and HTML/JSON response
```

### Registration and verification email

```text
auth/register.php
  -> users row starts unverified/pending
  -> account_tokens stores SHA-256 token hash + 15-minute expiry
  -> includes/mailer.php builds verification email and sends via PHPMailer/SMTP
  -> auth/verification-pending.php can issue a replacement token (rate-limited)
  -> auth/verify-email.php validates/consumes token and activates the account
```

The raw verification token is included only in the emailed URL; the database stores its hash. The resend page identifies the pending user from a session ID and re-reads the account from `users`; it does not accept a recipient email from the browser.

### Student order path

```text
student/shop/index.php
  -> Add to Cart: cart_items
  -> Buy Now: short-lived session selection, separate from cart
student/cart/index.php
  -> update/remove cart_items
student/checkout/index.php
  -> validate payment method, products/product_sizes, selected stock and price
  -> transaction creates orders, payments, order_items, order_status_history,
     inventory_movements and notifications
  -> student/receipt.php -> includes/pages/receipt.php
student/order-status/index.php or student/order-history/index.php
  -> shared includes/pages/orders.php
```

### Admin fulfillment and claims

```text
admin/order-status/index.php
  -> includes/pages/orders.php
  -> includes/order_workflows.php approves/rejects payment transactionally
  -> successful payment stamps verified_at, issues a stable secure QR, and sets Ready to Claim
admin/ready-to-claim/index.php
  -> lists only Paid + Ready to Claim orders
admin/qr-scanner/index.php
  -> api/qr/claim.php verifies token and order
  -> explicit Admin handover confirmation transactionally saves claim_date and moves the order to history
```

## 6. Database guide

The SQL dump and migrations describe the schema; the live database is the final authority if it has received local changes. Queries are generally issued in the page handling the operation or in shared `includes/` helpers. All `orders` and `payments` remain separate concepts: claiming never changes payment status.

### Tables

| Table | Purpose and important data | Main code that reads/writes it |
|---|---|---|
| `users` | `id` PK; `email` and `username` are unique; `password_hash`, `role`, `is_verified`, `is_active`, `account_status`, `registration_status`, `email_verified_at`, recovery hashes/questions, login timestamps, and `auth_version`. | Auth routes; `includes/functions.php`; Admin users/settings; dashboards, orders, receipts, schedule, QR API. |
| `products` | `id` PK; `name`, `category`, `price`, `stock`, `has_sizes`, `image_url`, `is_active`, and timestamps. For sized products, `stock` summarizes active `product_sizes`. | Admin Inventory; Student shop/cart/checkout; dashboard and order/receipt views. |
| `product_sizes` | `id` PK; `product_id` FK to `products.id`, `size`, `stock`, `is_active`; unique `(product_id, size)`. | Admin Inventory and shared inventory helpers; Student shop/cart/checkout. |
| `cart_items` | `id` PK; `user_id` FK to `users.id`, `product_id` FK to `products.id`, `selected_size`, `quantity`; unique `(user_id, product_id, selected_size)`. | Student shop, cart, dashboard, and checkout. |
| `orders` | `id` PK; `user_id` FK to `users.id`, unique `reference_number`, `total_amount`, `payment_method`, `payment_status`, `order_status`, `verified_at`, unique `qr_token_hash` and QR nonce/legacy token, `claim_date` (actual received time), `cancelled_at`, `cancellation_reason`, `claimed_by_admin_id` FK, timestamps. Legacy pickup fields are retained for existing data only. | Checkout; shared orders/workflows/receipt; dashboard/sales; QR API. |
| `order_items` | `id` PK; `order_id` FK to `orders.id`, `product_id` FK to `products.id`, `quantity`, `selected_size`, and historical `unit_price`. | Checkout; shared order details and receipt; dashboard and QR API. |
| `payments` | `id` PK; `order_id` FK to `orders.id`, `payment_method`, `amount`, `status`, `transaction_reference`, `proof_path`, `rejection_reason`, `reviewed_by_admin_id` FK, `reviewed_at`, and `paid_at`. | Checkout; shared order/payment workflows; proof API and receipt. |
| `order_status_history` | `id` PK; `order_id` FK, `old_status`, `new_status`, `changed_by_user_id` FK, `reason`, and `created_at`. | `includes/order_workflows.php`; checkout; Admin/Student order details; QR claim API. |
| `pickup_schedules` | Legacy table retained to preserve existing records; no longer used for checkout or order collection. | Existing installations only. |
| `notifications` | `id` PK; `user_id` FK to `users.id`, title/message, optional `related_order_id` FK, `is_read`, timestamp. | Checkout and shared workflows; QR claims; Student dashboard/notifications. |
| `account_tokens` | `id` PK; `user_id` FK, `token_type`, SHA-256 `token_hash`, `expires_at`, `used_at`, `created_at`. | Registration, resend, verification, recovery/reset, and token helpers. |
| `auth_attempts` | `id` PK; `action`, hashed `identifier_hash` and `ip_hash`, `succeeded`, `created_at`. | Shared rate-limit helpers and auth routes. |
| `email_delivery_log` | `id` PK; `purpose`, hashed `recipient_hash`, `delivery_status`, sanitized `error_message`, `created_at`. The recipient address and SMTP password are not stored. | `includes/mailer.php`. |
| `admin_activity` | `id` PK; `admin_id` FK to `users.id`, `action`, `details`, `created_at`. | Admin pages, shared order workflows, QR claim, recovery/settings. |
| `inventory_movements` | `id` PK; required `product_id` FK; optional `order_id` and `admin_id` FKs; `selected_size`, signed `quantity_change`, `reason`, `created_at`. | Shared inventory helpers, Admin Inventory, checkout, cancellation workflow. |
| `schema_migrations` | `migration_key` PK and `applied_at`; prevents repeating named migrations. | `includes/migrations.php`. |
| `password_reset_tokens` | Legacy table: `id` PK, `user_id` FK, raw legacy `token`, expiry/use timestamps. Current reset code uses `account_tokens`; no current PHP workflow uses this table. Keep it until a deliberate migration/removal is planned. | Created by setup/SQL dump only. |

### Relationships and key rules

```text
users 1---* orders 1---* order_items *---1 products
users 1---* cart_items *---1 products
products 1---* product_sizes
orders 1---* payments
orders 1---* order_status_history
users 1---* notifications; notifications optionally reference orders
pickup_schedules 1---* orders (legacy relationship retained for existing data)
users 1---* account_tokens
products/orders/users 1---* inventory_movements (optional order/Admin references)
```

The SQL defines foreign keys for these relationships, with cascades or `SET NULL` where specified. `orders.qr_token_hash` is unique; `qr_token_nonce` and the configured `APP_KEY` derive a non-guessable QR value. `account_tokens.token_hash` stores a hash, not the raw emailed token. Review `database/cscqc_ecommerce.sql` and the migration functions together before changing columns or constraints.

## 7. Manual coding guide

| To change… | Start here | Keep in mind |
|---|---|---|
| Login/registration/verification behavior | `auth/login.php`, `auth/register.php`, `auth/verification-pending.php`, `auth/verify-email.php`, `includes/functions.php` | Preserve CSRF checks, rate limits, secure token hashes, role checks, expiry, and one-use semantics. |
| Password reset or Admin recovery | `auth/forgot-password.php`, `auth/reset-password.php`, `includes/pages/settings.php`, `includes/mailer.php` | Tokens and OTPs are one-use; password changes increment `auth_version`. Never log raw secrets. |
| SMTP sender/template/config | `.env` locally, `.env.example` placeholders, `config/app.php`, `includes/mailer.php` | `includes/mailer.php` is the centralized transport; keep configuration out of frontend code. Test preflight before sending. |
| Common Admin/Student panel layout | `assets/css/shared/shared.css` | Shared header/sidebar, forms, tables, dialogs, colors, and responsive rules; keep page-specific rules out of this file. |
| Admin header/sidebar/notifications | `includes/pages/admin_chrome.php`, `assets/css/shared/shared.css` | Every Admin page calls `cvcarts_admin_header()` and `cvcarts_admin_sidebar($active)`; edit chrome here once instead of per page. |
| Admin dashboard visuals | `assets/css/admin/dashboard.css` | Adjust Admin dashboard statistics, sales chart, and grid without affecting the Student dashboard. |
| Student dashboard visuals | `assets/css/student/dashboard.css` | Adjust Student dashboard statistics and grid without affecting the Admin dashboard. |
| Other Admin page visuals | Matching `assets/css/admin/<page>.css` | Each Admin page has its own stylesheet; route-specific classes/selectors are documented above. |
| Other Student page visuals | Matching `assets/css/student/<page>.css` | Each Student page has its own stylesheet; route-specific classes/selectors are documented above. |
| Inventory layout | `admin/inventory/index.php`, `assets/css/admin/inventory.css` | Inventory has its own independent theme; Bootstrap 5 and Bootstrap Icons load before its CSS. |
| Login/registration/recovery visual design | `assets/css/auth/*.css` and matching `auth/*.php` | Sign-in, sign-up, recovery, password reset, and verification routes each load their own stylesheet. |
| Dialog/modal behavior | `assets/js/modal.js` and `dialog.app-modal` rules in `assets/css/shared/shared.css` | Use native `<dialog>` and existing `data-modal-open`, `data-modal-close`, `data-modal-form`, and `data-confirm` conventions. |
| Product fields or stock rules | `admin/inventory/index.php`, `student/shop/index.php`, `student/cart/index.php`, `student/checkout/index.php`, `includes/functions.php` | Update validation, display, and transaction logic together. Sized stock lives in `product_sizes`; `products.stock` is a summary for sized products. |
| Checkout/payment behavior | `student/checkout/index.php`, `includes/order_workflows.php`, `includes/pages/orders.php` | Payment state is not order state. Do not mark an order paid merely because it was claimed. |
| Order search/sorting/status | `includes/pages/orders.php` | Admin and Student wrappers share the page; Student SQL must remain scoped to their user ID. Sort choices should map through allowlisted SQL fragments. |
| Ready-to-claim / QR workflow | `includes/functions.php`, `admin/ready-to-claim/index.php`, `admin/qr-scanner/index.php`, `api/qr/claim.php`, `includes/order_workflows.php` | Keep unpredictable token checks server-side, Admin-only authorization, CSRF, transactional explicit claim confirmation, and history/audit records. |
| Database columns/tables | `includes/migrations.php`, `database/cscqc_ecommerce.sql`, `database/setup.php` | Add an idempotent migration, update schema docs, back up data, and verify existing rows. Avoid resetting production data. |
| Add a Student/Admin route | Create a route in the relevant role folder; use `includes/auth.php`, `cvcarts_require_login()`, `cvcarts_csrf_token()`/`cvcarts_require_csrf()`, and shared styles | Link it from the role navigation and preserve role/ownership checks in the backend. For Admin routes, reuse `cvcarts_admin_header()` and `cvcarts_admin_sidebar($active)` from `includes/pages/admin_chrome.php`. |

## 8. Debugging checklist

| Symptom | Files to inspect | What to check first |
|---|---|---|
| Page fails to load | Requested page, `includes/auth.php`, `config/app.php`, `config/database.php` | Apache/PHP error log; include paths; PHP syntax; PDO connection; migration errors. |
| Button appears but does nothing | Page PHP, its inline script, `assets/js/modal.js` if dialog-related | Browser Console for JavaScript errors; inspect button/form name and event listeners; check whether the form has the expected action/method. |
| Form does not submit | Form page and its POST handler | Network tab request method/status/payload; hidden CSRF token; required fields; redirect/flash response; server-side validation message. |
| Data does not save | Page handler, `includes/functions.php`, `includes/order_workflows.php`, `includes/migrations.php` | PHP log and PDO exception; transaction rollback; table/column/migration state; prepared statement parameters. |
| Login fails | `auth/login.php`, `includes/functions.php`, `users` table | Role/email/username lookup, `password_verify`, active/verified/account state, auth attempt throttling, and `auth_version` session. |
| Email does not send | `.env` (locally), `config/app.php`, `includes/mailer.php`, `email_delivery_log` | Confirm `.env` parse and SMTP host/port/TLS/auth/sender configuration; run the SMTP preflight; inspect server PHP error log and the sanitized delivery-log failure. Never print the password. |
| Resend link is missing/invalid | `auth/verification-pending.php`, `auth/verify-email.php`, `includes/functions.php`, `account_tokens` | Pending user session ID, rate-limit rows, latest token hash/expiry/used state, base URL, SMTP failure record. |
| Payment proof is inaccessible | `student/checkout/index.php`, `includes/pages/orders.php`, `api/payments/proof.php`, `uploads/payment-proofs/.htaccess` | Latest `payments.proof_path`, file exists, valid generated filename/MIME, owner/Admin authorization, Apache access rules. |
| Order/claim status is wrong | `includes/order_workflows.php`, `includes/pages/orders.php`, `api/qr/claim.php`, `order_status_history` | Check payment and order status separately; history, claim date/Admin, transaction outcome, and associated notification. |
| Stock is incorrect | `admin/inventory/index.php`, `student/checkout/index.php`, `includes/functions.php`, `product_sizes`, `inventory_movements` | Compare regular stock vs per-size stock; verify checkout/cancellation transactions and movement reason/delta. |
| Search/sort is wrong | The matching list page (`admin/users/index.php`, `admin/inventory/index.php`, `student/shop/index.php`, or `includes/pages/orders.php`) | Check GET parameter names, allowlisted sort map, WHERE conditions, bound values, count query, pagination and preserved query string. |
| Styling or modal is broken | Page stylesheet link, `assets/css/shared/shared.css`, matching `assets/css/admin/`, `assets/css/student/`, or `assets/css/auth/` stylesheet, `assets/js/modal.js` | Network tab for CSS/JS 404s; inspect computed styles and dialog open state; check mobile viewport and Console errors. |

### Browser and PHP tools

- **Developer Tools → Console:** JavaScript exceptions, failed selectors, camera permission errors, and browser warnings.
- **Developer Tools → Network:** Confirm the form/API request actually fires; inspect status code, request method, response, and missing assets. Do not copy secret-bearing payloads into a public report.
- **PHP/Apache error logs:** In XAMPP these are normally available from the Control Panel or under the XAMPP Apache/PHP log locations. Exact locations depend on the local installation. PHP `error_log()` output is where mail/claim diagnostics are sent.
- **Database manager:** Use phpMyAdmin or a trusted MySQL client to inspect schema and records. Start with `SHOW TABLES`, `DESCRIBE table_name`, and narrowly scoped queries by known test IDs. Do not share production rows.
- **SQL errors:** Read the exact table/column/constraint and failing operation. Check whether migrations ran and whether the connection targets the intended database before changing schema or data.

## 9. Developer quick reference

| I want to… | Open this file | Main area |
|---|---|---|
| Change Admin overview | `admin/dashboard.php` | Dashboard queries and cards |
| Change student overview | `student/dashboard.php` | Student-owned summaries |
| Add/edit products or stock | `admin/inventory/index.php` and `includes/functions.php` | Product forms and shared stock operations |
| Change student product search | `student/shop/index.php` | GET filters, product query, Buy Now/Add to Cart |
| Change cart behavior | `student/cart/index.php` | Cart quantity, size, remove/update |
| Change checkout/payment behavior | `student/checkout/index.php` | Order transaction, payment fields, stock reservation |
| Change order status/payment review | `includes/pages/orders.php`, `includes/order_workflows.php` | Shared order UI and transactional rules |
| Change QR scanner/claim endpoint | `admin/qr-scanner/index.php`, `api/qr/claim.php` | Browser scanner and server validation |
| Change receipt | `includes/pages/receipt.php` | Receipt fields, print view, QR display |
| Change Student notifications | `student/notifications/index.php`, `includes/order_workflows.php` | Listing/read state and notification creation |
| Change Student/Admin settings | `includes/pages/settings.php` | Profile, password, security question |
| Change email templates/transport | `includes/mailer.php` | SMTP setup and messages |
| Change shared environment loading | `.env` locally, `config/app.php` | Environment variables and base URL |
| Change DB connection | `.env` locally, `config/database.php` | PDO DSN/options and connection error path |
| Change schema | `includes/migrations.php`, `database/cscqc_ecommerce.sql` | Migration and canonical schema |
| Change sign-in visually | `assets/css/auth/signin.css` | Sign-in-only layout and controls |
| Change sign-up visually | `assets/css/auth/signup.css` | Sign-up-only form and validation |
| Change recovery/reset/verification screens | `assets/css/auth/forgot-password.css`, `assets/css/auth/reset-password.css`, `assets/css/auth/verification.css` | One stylesheet per route or route family |
| Change common Admin/Student styling | `assets/css/shared/shared.css` | Components intentionally shared across role panels |
| Change Admin dashboard without changing Student dashboard | `assets/css/admin/dashboard.css` | Admin statistics/charts |
| Change Student dashboard without changing Admin dashboard | `assets/css/student/dashboard.css` | Student summaries |
| Change Inventory-only styling | `assets/css/admin/inventory.css` | Inventory page layout, table, toolbar, and dialogs |
| Change modal interactions | `assets/js/modal.js` | Shared dialogs and form-submit safeguards |

## 10. Notes on legacy and generated files

- `admin/shop/index.php` is a compatibility redirect to Inventory, not a second catalog-management implementation.
- `admin/order-status/`, `admin/order-history/`, `student/order-status/`, and `student/order-history/` contain thin wrappers. The actual shared behavior is in `includes/pages/orders.php`.
- Admin and Student settings and receipt routes likewise wrap shared files in `includes/pages/`.
- The legacy `password_reset_tokens` table remains in the base schema but current password recovery uses `account_tokens`. Do not delete the table until its compatibility/history implications have been checked.
- `orders.qr_token` remains in the schema for legacy-token compatibility. New QR values use the nonce/APP_KEY scheme and a stored hash; avoid building new code around raw database order IDs or personal information.
- Composer's `vendor/` code and PHPMailer translations are dependencies, not application-owned modules. Do not patch them directly; Composer updates replace generated files.
- The project tree contains no application test suite. Syntax checks and manual flows should be run after PHP changes; SMTP, camera, receipt printing, and database transactions require runtime testing in the local XAMPP setup.

## 11. Security and maintenance rules

1. Keep `.env` private and preserve a safe `.env.example`; never put secrets in this README, PHP, JavaScript, or browser responses.
2. Keep prepared statements, role checks, record ownership checks, CSRF validation, rate limits, and transaction boundaries when editing request handlers.
3. Do not log raw passwords, SMTP credentials, verification/reset tokens, or uploaded proof contents.
4. Treat payment proof files as private. Continue serving them only through `api/payments/proof.php`, which checks access and validates the stored file.
5. Back up the database before schema work. Add idempotent migrations instead of deleting/resetting existing user or order records.
6. Update this guide and the schema documentation whenever modules, files, tables, or important workflows change.
7. Do not delete files merely because they look unused. Verify all route references, compatibility uses, and legacy database responsibilities first.
