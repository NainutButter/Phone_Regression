# SmartPhone Stock

A PHP 8.1+ and MySQL inventory application for phone retailers. It includes account registration, product and supplier management, stock tracking, sales, purchase orders, regression evaluation, forecasts, purchase advice, a dashboard, and sales reports.

## Requirements

- PHP 8.1 or later with `PDO` and `pdo_mysql`
- MySQL 8.0+
- A web server configured to use `public/` as its document root
- Internet access for the optional Google Fonts and Chart.js CDN assets

No Composer packages or JavaScript build step are required.

## Install

1. Create/import the database schema:

   ```sh
   mysql -u root -p < database/database.sql
   ```

   The SQL creates and selects the `smartphone_stock` database.

2. Set database connection values in a private `.env.local` file in the project root (or as environment variables for PHP):

   ```sh
   cp .env.example .env.local
   chmod 600 .env.local
   ```

   Edit `.env.local` with the connection details. Environment variables take precedence over values in the file. `.env.local` is ignored by Git and should never be committed. Defaults, when neither source is configured, are `127.0.0.1`, `3306`, `smartphone_stock`, `root`, and an empty password. For deployment, use a dedicated least-privilege MySQL account and HTTPS.

   If you use the project-local MySQL instance created under `.local/mysql`, start it before PHP:

   ```sh
   mysqld --no-defaults --datadir="$PWD/.local/mysql" \
     --port=3307 --bind-address=127.0.0.1 \
     --socket="$PWD/.local/mysql/mysql.sock" \
     --pid-file="$PWD/.local/mysql/mysql.pid" \
     --log-error="$PWD/.local/mysql/server.log"
   ```

3. Serve the `public/` directory. For local development:

   ```sh
   php -S 127.0.0.1:8000 -t public
   ```

   Then open `http://127.0.0.1:8000` and register an account. There is no default user or shared demo password.

4. Optional demo catalog: after registering, edit `@seed_email` at the top of `database/seed.sql` to match that account, then run:

   ```sh
   mysql -u root -p < database/seed.sql
   ```

   Seed rows are scoped to that account and will not be added if their SKU already exists.

## Main workflows

- Products represent sellable SKUs; storage and color combinations are separate SKUs.
- Inventory supports stock-in, stock-out, physical-count adjustment, and transaction history.
- Sales may contain multiple SKUs. The stock check, sale lines, stock changes, and audit entries are committed atomically. Cancelling a sale restores stock and records the reversal.
- Purchase orders begin as drafts. Marking an order as ordered counts its units as incoming; receiving it increases stock and can only be done once.
- Forecasting uses completed sales grouped by observed sale dates. Missing dates are not filled with zero sales. A time-ordered holdout evaluates Linear, Polynomial (degree 2), and Multiple Linear Regression, and recommends the lowest test-set MAE when enough observations exist.
- Purchase advice forecasts over the SKU's lead time and applies `ceil(forecast demand) + safety stock - current stock - ordered incoming stock`, with a floor of zero. It does not create purchase orders automatically.
- Account-owned data is filtered by the authenticated user. Forms use CSRF tokens, database access uses PDO prepared statements, and output is HTML-escaped.

## Regression checks

Run the dependency-free CLI tests:

```sh
php tests/regression.php
```

The test script checks regression predictions, evaluation metrics, and the purchase recommendation example. Database-backed workflows require a running MySQL server and an imported schema.

## Layout

- `app/Core/` — database, session authentication, CSRF, shared helpers
- `app/Regression/` — regression model interface, implementations, and metrics
- `app/Services/` — inventory, sales, forecasting, purchase advice, and purchasing logic
- `config/database.php` — PDO connection configuration
- `database/` — schema and optional demo seed
- `public/` — front controller and static assets
- `tests/` — lightweight CLI regression tests
# Phone_Regression
