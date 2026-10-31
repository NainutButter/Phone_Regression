<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\ForecastService;
use App\Services\InventoryService;
use App\Services\PurchaseAdvisor;
use App\Services\PurchaseOrderService;
use App\Services\SalesService;
session_name('smartphone_stock_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function form_open(string $action, array $hidden = []): string
{
    $html = '<form method="post" class="post-form">' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($hidden as $key => $value) {
        $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
    }
    return $html;
}

function selected(mixed $actual, mixed $expected): string
{
    return (string) $actual === (string) $expected ? ' selected' : '';
}

function option_list(array $rows, string $valueField, string $labelField, mixed $selectedValue = null): string
{
    $html = '';
    foreach ($rows as $row) {
        $html .= '<option value="' . e($row[$valueField]) . '"' . selected($selectedValue, $row[$valueField]) . '>'
            . e($row[$labelField]) . '</option>';
    }
    return $html;
}

function page_layout(string $title, string $content, ?array $user): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $active = (string) ($_GET['page'] ?? 'dashboard');
    $links = [
        'dashboard' => ['Dashboard', '◫'],
        'products' => ['Products', '▣'],
        'inventory' => ['Inventory', '▤'],
        'sales' => ['Sales', '↗'],
        'forecast' => ['Regression & Forecast', '⌁'],
        'recommendations' => ['Purchase advice', '✦'],
        'suppliers' => ['Suppliers', '♙'],
        'purchase-orders' => ['Purchase orders', '▧'],
        'reports' => ['Reports', '▥'],
        'settings' => ['Settings', '⚙'],
    ];
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> · SmartPhone Stock</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/app.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script defer src="assets/js/app.js"></script>
    </head>
    <body>
    <?php if ($user): ?>
        <aside class="sidebar">
            <a class="brand" href="?page=dashboard"><span class="brand-mark">S</span><span>SmartPhone<span class="brand-light">Stock</span></span></a>
            <div class="nav-caption">WORKSPACE</div>
            <nav class="side-nav">
                <?php foreach ($links as $key => [$label, $icon]): ?>
                    <a class="<?= $active === $key ? 'active' : '' ?>" href="?page=<?= e($key) ?>"><span class="nav-icon"><?= $icon ?></span><?= e($label) ?></a>
                <?php endforeach; ?>
                <?= form_open('logout') ?><button class="side-logout" type="submit"><span class="nav-icon">↪</span>Log out</button></form>
            </nav>
            <div class="sidebar-bottom">
                <div class="support-card"><span class="support-icon">?</span><strong>Need help?</strong><small>Explore your inventory insights</small></div>
            </div>
        </aside>
        <main class="main-shell">
            <header class="topbar"><div class="breadcrumb">Workspace <span>/</span> <?= e($title) ?></div><div class="user-menu"><span class="online-dot"></span><span class="user-name"><?= e($user['name']) ?></span><span class="avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span></div></header>
            <section class="page-content">
                <?php if ($flash): ?><div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?><button type="button" class="notice-close" aria-label="Dismiss">×</button></div><?php endif; ?>
                <?= $content ?>
            </section>
        </main>
    <?php else: ?>
        <?php if ($flash): ?><div class="auth-notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?= $content ?>
    <?php endif; ?>
    </body>
    </html><?php
}

function page_heading(string $eyebrow, string $title, string $description, string $action = ''): string
{
    return '<div class="page-heading"><div><div class="eyebrow">' . e($eyebrow) . '</div><h1>' . e($title) . '</h1><p>' . e($description) . '</p></div>' . $action . '</div>';
}

function pill(string $text, string $class = ''): string
{
    return '<span class="pill ' . e($class) . '">' . e($text) . '</span>';
}

function product_options(array $products): string
{
    $html = '';
    foreach ($products as $product) {
        $label = $product['brand'] . ' ' . $product['model'] . ' · ' . $product['storage'] . ' · ' . $product['color'];
        $html .= '<option value="' . e($product['id']) . '">' . e($label) . '</option>';
    }
    return $html;
}

try {
    $db = require __DIR__ . '/../config/database.php';
    $auth = new Auth($db);
    $user = $_SESSION['user'] ?? null;
    $page = (string) ($_GET['page'] ?? 'dashboard');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::verify($_POST['_csrf'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'register') {
                $auth->register((string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
                flash('success', 'Your account is ready. Welcome to SmartPhone Stock.');
                redirect('?page=dashboard');
            }
            if ($action === 'login') {
                $auth->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
                flash('success', 'Welcome back.');
                redirect('?page=dashboard');
            }
            if (!$user) {
                throw new RuntimeException('Please sign in to continue.');
            }
            $userId = (int) $user['id'];
            switch ($action) {
                case 'logout':
                    $auth->logout();
                    redirect('?');
                case 'product_save':
                    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                    $supplierId = ($_POST['supplier_id'] ?? '') === '' ? null : positive_int($_POST['supplier_id'], 'Supplier');
                    if ($supplierId !== null) {
                        $supplierCheck = $db->prepare('SELECT id FROM suppliers WHERE id = :id AND user_id = :user_id');
                        $supplierCheck->execute(['id' => $supplierId, 'user_id' => $userId]);
                        if (!$supplierCheck->fetch()) {
                            throw new RuntimeException('Selected supplier not found.');
                        }
                    }
                    $fields = [
                        'user_id' => $userId, 'supplier_id' => $supplierId,
                        'sku' => trim((string) ($_POST['sku'] ?? '')),
                        'brand' => trim((string) ($_POST['brand'] ?? '')),
                        'model' => trim((string) ($_POST['model'] ?? '')),
                        'storage' => trim((string) ($_POST['storage'] ?? '')),
                        'ram' => trim((string) ($_POST['ram'] ?? '')) ?: null,
                        'color' => trim((string) ($_POST['color'] ?? '')),
                        'cost_price' => filter_var($_POST['cost_price'] ?? null, FILTER_VALIDATE_FLOAT),
                        'selling_price' => filter_var($_POST['selling_price'] ?? null, FILTER_VALIDATE_FLOAT),
                        'minimum_stock' => filter_var($_POST['minimum_stock'] ?? null, FILTER_VALIDATE_INT),
                        'safety_stock' => filter_var($_POST['safety_stock'] ?? null, FILTER_VALIDATE_INT),
                        'lead_time_days' => filter_var($_POST['lead_time_days'] ?? null, FILTER_VALIDATE_INT),
                        'image_url' => trim((string) ($_POST['image_url'] ?? '')) ?: null,
                    ];
                    if (in_array('', [$fields['sku'], $fields['brand'], $fields['model'], $fields['storage'], $fields['color']], true)
                        || $fields['cost_price'] === false || $fields['cost_price'] < 0
                        || $fields['selling_price'] === false || $fields['selling_price'] < 0
                        || $fields['minimum_stock'] === false || $fields['minimum_stock'] < 0
                        || $fields['safety_stock'] === false || $fields['safety_stock'] < 0
                        || $fields['lead_time_days'] === false || $fields['lead_time_days'] < 0
                        || ($fields['image_url'] !== null && (!filter_var($fields['image_url'], FILTER_VALIDATE_URL)
                            || !in_array(strtolower((string) parse_url($fields['image_url'], PHP_URL_SCHEME)), ['http', 'https'], true)))) {
                        throw new InvalidArgumentException('Complete the required product fields with valid non-negative values.');
                    }
                    if ($productId && $productId > 0) {
                        $sql = 'UPDATE products SET supplier_id=:supplier_id, sku=:sku, brand=:brand, model=:model, storage=:storage, ram=:ram, color=:color, cost_price=:cost_price, selling_price=:selling_price, minimum_stock=:minimum_stock, safety_stock=:safety_stock, lead_time_days=:lead_time_days, image_url=:image_url WHERE id=:id AND user_id=:user_id';
                        $fields['id'] = $productId;
                        $statement = $db->prepare($sql);
                        $statement->execute($fields);
                        if ($statement->rowCount() === 0) {
                            $check = $db->prepare('SELECT id FROM products WHERE id=:id AND user_id=:user_id');
                            $check->execute(['id' => $productId, 'user_id' => $userId]);
                            if (!$check->fetch()) {
                                throw new RuntimeException('Product not found.');
                            }
                        }
                    } else {
                        $initialStock = filter_var($_POST['stock'] ?? 0, FILTER_VALIDATE_INT);
                        if ($initialStock === false || $initialStock < 0) {
                            throw new InvalidArgumentException('Initial stock must be zero or more.');
                        }
                        $db->beginTransaction();
                        $statement = $db->prepare('INSERT INTO products (user_id,supplier_id,sku,brand,model,storage,ram,color,cost_price,selling_price,stock,minimum_stock,safety_stock,lead_time_days,image_url) VALUES (:user_id,:supplier_id,:sku,:brand,:model,:storage,:ram,:color,:cost_price,:selling_price,:stock,:minimum_stock,:safety_stock,:lead_time_days,:image_url)');
                        $fields['stock'] = $initialStock;
                        $statement->execute($fields);
                        $newId = (int) $db->lastInsertId();
                        if ($initialStock > 0) {
                            $log = $db->prepare("INSERT INTO stock_transactions (user_id,product_id,type,quantity,notes) VALUES (:user_id,:product_id,'in',:quantity,'Initial stock')");
                            $log->execute(['user_id' => $userId, 'product_id' => $newId, 'quantity' => $initialStock]);
                        }
                        $db->commit();
                    }
                    flash('success', 'Product saved.');
                    redirect('?page=products');
                case 'product_delete':
                    $id = positive_int($_POST['product_id'] ?? null, 'Product');
                    $statement = $db->prepare('DELETE FROM products WHERE id=:id AND user_id=:user_id');
                    $statement->execute(['id' => $id, 'user_id' => $userId]);
                    if ($statement->rowCount() !== 1) {
                        throw new RuntimeException('Product could not be deleted. Products with sales or stock history must be retained for audit purposes.');
                    }
                    flash('success', 'Product deleted.');
                    redirect('?page=products');
                case 'stock_change':
                    $productId = positive_int($_POST['product_id'] ?? null, 'Product');
                    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
                    $type = (string) ($_POST['type'] ?? '');
                    if ($quantity === false) {
                        throw new InvalidArgumentException('Enter a whole number for the stock change.');
                    }
                    if ($type === 'out') {
                        (new InventoryService($db, $userId))->changeStock($productId, positive_int($quantity, 'Quantity'), 'out', (string) ($_POST['notes'] ?? ''));
                    } elseif ($type === 'in') {
                        (new InventoryService($db, $userId))->changeStock($productId, positive_int($quantity, 'Quantity'), 'in', (string) ($_POST['notes'] ?? ''));
                    } elseif ($type === 'adjustment') {
                        (new InventoryService($db, $userId))->adjustStock($productId, $quantity, (string) ($_POST['notes'] ?? ''));
                    } else {
                        throw new InvalidArgumentException('Choose stock in or stock out.');
                    }
                    flash('success', 'Stock updated and transaction recorded.');
                    redirect('?page=inventory');
                case 'sale_create':
                    $saleId = (new SalesService($db, $userId))->createSale(
                        is_array($_POST['items'] ?? null) ? $_POST['items'] : [],
                        (string) ($_POST['payment_method'] ?? ''),
                        (string) ($_POST['sale_date'] ?? '')
                    );
                    flash('success', "Sale #{$saleId} recorded and stock updated.");
                    redirect('?page=sales');
                case 'sale_cancel':
                    (new SalesService($db, $userId))->cancelSale(positive_int($_POST['sale_id'] ?? null, 'Sale'));
                    flash('success', 'Sale cancelled and stock restored.');
                    redirect('?page=sales');
                case 'supplier_save':
                    $id = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $email = trim((string) ($_POST['email'] ?? ''));
                    if ($name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                        throw new InvalidArgumentException('Enter a supplier name and a valid email address.');
                    }
                    $values = [
                        'user_id' => $userId, 'name' => $name,
                        'contact_person' => trim((string) ($_POST['contact_person'] ?? '')) ?: null,
                        'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                        'email' => $email ?: null, 'address' => trim((string) ($_POST['address'] ?? '')) ?: null,
                    ];
                    if ($id && $id > 0) {
                        $values['id'] = $id;
                        $statement = $db->prepare('UPDATE suppliers SET name=:name,contact_person=:contact_person,phone=:phone,email=:email,address=:address WHERE id=:id AND user_id=:user_id');
                    } else {
                        $statement = $db->prepare('INSERT INTO suppliers (user_id,name,contact_person,phone,email,address) VALUES (:user_id,:name,:contact_person,:phone,:email,:address)');
                    }
                    $statement->execute($values);
                    flash('success', 'Supplier saved.');
                    redirect('?page=suppliers');
                case 'supplier_delete':
                    $statement = $db->prepare('DELETE FROM suppliers WHERE id=:id AND user_id=:user_id');
                    $statement->execute(['id' => positive_int($_POST['supplier_id'] ?? null, 'Supplier'), 'user_id' => $userId]);
                    if ($statement->rowCount() !== 1) {
                        throw new RuntimeException('Supplier could not be deleted; it may still be referenced by purchase orders.');
                    }
                    flash('success', 'Supplier deleted.');
                    redirect('?page=suppliers');
                case 'po_create':
                    $orderId = (new PurchaseOrderService($db, $userId))->create(
                        positive_int($_POST['supplier_id'] ?? null, 'Supplier'),
                        is_array($_POST['items'] ?? null) ? $_POST['items'] : []
                    );
                    flash('success', "Draft purchase order #{$orderId} created. Confirm it when ready to order.");
                    redirect('?page=purchase-orders');
                case 'po_status':
                    (new PurchaseOrderService($db, $userId))->setStatus(
                        positive_int($_POST['order_id'] ?? null, 'Purchase order'),
                        (string) ($_POST['status'] ?? '')
                    );
                    flash('success', 'Purchase order status updated.');
                    redirect('?page=purchase-orders');
                case 'po_receive':
                    (new PurchaseOrderService($db, $userId))->receive(positive_int($_POST['order_id'] ?? null, 'Purchase order'));
                    flash('success', 'Purchase order received. Stock was updated exactly once.');
                    redirect('?page=purchase-orders');
                case 'forecast_run':
                    $productId = positive_int($_POST['product_id'] ?? null, 'Product');
                    $days = positive_int($_POST['days'] ?? null, 'Forecast period');
                    $modelName = (string) ($_POST['model'] ?? '');
                    $forecastService = new ForecastService($db, $userId);
                    $history = $forecastService->history($productId);
                    $result = $forecastService->generateForecast($history, $modelName, $days);
                    $metrics = $forecastService->compareModels($history);
                    $save = $db->prepare('INSERT INTO forecast_results (user_id,product_id,model_name,horizon_days,equation,metrics,forecast_data) VALUES (:user_id,:product_id,:model_name,:days,:equation,:metrics,:data)');
                    $save->execute([
                        'user_id' => $userId, 'product_id' => $productId, 'model_name' => $modelName, 'days' => $days,
                        'equation' => $result['equation'],
                        'metrics' => json_encode($metrics['models'], JSON_THROW_ON_ERROR),
                        'data' => json_encode($result['forecast'], JSON_THROW_ON_ERROR),
                    ]);
                    flash('success', 'Forecast calculated and saved.');
                    redirect('?page=forecast&product_id=' . $productId . '&model=' . rawurlencode($modelName) . '&days=' . $days);
                default:
                    throw new InvalidArgumentException('Unknown form action.');
            }
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log((string) $exception);
            flash('error', str_contains($exception->getMessage(), 'Duplicate') ? 'That SKU or supplier name is already in use.' : 'The database could not complete this action. Please check the entered data and try again.');
            redirect('?page=' . rawurlencode($page));
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('error', $exception->getMessage());
            redirect('?page=' . rawurlencode($page));
        }
    }

    if (!$user) {
        $mode = (string) ($_GET['mode'] ?? 'login');
        $register = $mode === 'register';
        ob_start(); ?>
        <main class="auth-page">
            <div class="auth-visual"><a class="brand brand-white" href="?"><span class="brand-mark">S</span><span>SmartPhone<span class="brand-light">Stock</span></span></a><div class="auth-hero"><span class="eyebrow eyebrow-light">INVENTORY, MADE INTELLIGENT</span><h1>Know what sells.<br><em>Plan what’s next.</em></h1><p>One clear view of your products, sales, and inventory trends.</p><div class="auth-mini-stats"><div><strong>01</strong><span>Track inventory</span></div><div><strong>02</strong><span>Forecast demand</span></div><div><strong>03</strong><span>Buy with confidence</span></div></div></div><div class="auth-footer">SmartPhone Stock · Inventory & forecasting</div></div>
            <div class="auth-panel"><div class="auth-card"><div class="eyebrow"><?= $register ? 'GET STARTED' : 'WELCOME BACK' ?></div><h2><?= $register ? 'Create your account' : 'Sign in to your workspace' ?></h2><p><?= $register ? 'Set up your private inventory workspace.' : 'Enter your details to access your dashboard.' ?></p>
                <?= form_open($register ? 'register' : 'login') ?>
                <?php if ($register): ?><label>Full name<input name="name" required autocomplete="name" maxlength="120"></label><?php endif; ?>
                <label>Email address<input name="email" type="email" required autocomplete="email" maxlength="190"></label>
                <label>Password<input name="password" type="password" required minlength="<?= $register ? '8' : '1' ?>" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>"></label>
                <button class="button button-primary button-wide" type="submit"><?= $register ? 'Create account' : 'Sign in' ?><span>→</span></button></form>
                <div class="auth-switch"><?= $register ? 'Already have an account?' : 'New to SmartPhone Stock?' ?> <a href="?mode=<?= $register ? 'login' : 'register' ?>"><?= $register ? 'Sign in' : 'Create an account' ?></a></div>
            </div><div class="auth-legal">Your account data is private to your workspace.</div></div>
        </main>
        <?php $content = ob_get_clean();
        page_layout($register ? 'Create account' : 'Sign in', $content, null);
        exit;
    }

    $userId = (int) $user['id'];
    $pageTitles = [
        'dashboard' => 'Dashboard', 'products' => 'Products', 'inventory' => 'Inventory', 'sales' => 'Sales',
        'forecast' => 'Regression & Forecast', 'recommendations' => 'Purchase advice', 'suppliers' => 'Suppliers',
        'purchase-orders' => 'Purchase orders', 'reports' => 'Reports', 'settings' => 'Settings',
    ];
    if (!isset($pageTitles[$page])) {
        $page = 'dashboard';
    }
    $title = $pageTitles[$page];
    ob_start();

    if ($page === 'dashboard') {
        $statsQuery = $db->prepare(
            "SELECT COUNT(*) product_count, COALESCE(SUM(stock),0) stock_units,
                    COUNT(DISTINCT brand) brand_count,
                    COALESCE(SUM(stock * cost_price),0) inventory_value
             FROM products WHERE user_id=:user_id"
        );
        $statsQuery->execute(['user_id' => $userId]);
        $stats = $statsQuery->fetch();
        $month = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE user_id=:user_id AND status='completed' AND sale_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')");
        $month->execute(['user_id' => $userId]);
        $monthSales = (float) $month->fetchColumn();
        $low = $db->prepare('SELECT id,brand,model,storage,stock,minimum_stock FROM products WHERE user_id=:user_id AND stock <= minimum_stock ORDER BY stock ASC, brand LIMIT 6');
        $low->execute(['user_id' => $userId]);
        $lowStock = $low->fetchAll();
        $best = $db->prepare("SELECT p.brand,p.model,SUM(si.quantity) units FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE p.user_id=:user_id AND s.status='completed' GROUP BY p.id ORDER BY units DESC LIMIT 5");
        $best->execute(['user_id' => $userId]);
        $bestSellers = $best->fetchAll();
        $monthly = $db->prepare("SELECT DATE_FORMAT(sale_date,'%b %Y') label, DATE_FORMAT(sale_date,'%Y-%m') sort_date, SUM(total_amount) total FROM sales WHERE user_id=:user_id AND status='completed' AND sale_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH) GROUP BY sort_date,label ORDER BY sort_date");
        $monthly->execute(['user_id' => $userId]);
        $monthlyRows = $monthly->fetchAll();
        $brandSales = $db->prepare("SELECT p.brand,SUM(si.quantity) units FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE p.user_id=:user_id AND s.status='completed' GROUP BY p.brand ORDER BY units DESC LIMIT 6");
        $brandSales->execute(['user_id' => $userId]);
        $brandRows = $brandSales->fetchAll();
        $stockBrands = $db->prepare('SELECT brand,SUM(stock) units FROM products WHERE user_id=:user_id GROUP BY brand ORDER BY units DESC LIMIT 6');
        $stockBrands->execute(['user_id' => $userId]);
        $stockRows = $stockBrands->fetchAll();
        $ordersCount = $db->prepare("SELECT COUNT(*) FROM purchase_orders WHERE user_id=:user_id AND status='ordered'");
        $ordersCount->execute(['user_id' => $userId]);
        echo page_heading('OVERVIEW', 'Good to see you, ' . $user['name'], 'Here’s what’s happening across your inventory today.', '<a class="button button-primary" href="?page=sales">＋ Record a sale</a>');
        ?><div class="stats-grid">
            <div class="stat-card"><span class="stat-label">PRODUCTS</span><strong><?= (int) $stats['product_count'] ?></strong><small>Unique phone SKUs</small><span class="stat-mark blue">▣</span></div>
            <div class="stat-card"><span class="stat-label">UNITS IN STOCK</span><strong><?= (int) $stats['stock_units'] ?></strong><small><?= count($lowStock) ?> need attention</small><span class="stat-mark teal">▤</span></div>
            <div class="stat-card"><span class="stat-label">SALES THIS MONTH</span><strong><?= e(number_format($monthSales, 0)) ?><small class="currency"> THB</small></strong><small>Completed sales only</small><span class="stat-mark orange">↗</span></div>
            <div class="stat-card"><span class="stat-label">INVENTORY VALUE</span><strong><?= e(number_format((float) $stats['inventory_value'], 0)) ?><small class="currency"> THB</small></strong><small><?= (int) $stats['brand_count'] ?> brands · <?= (int) $ordersCount->fetchColumn() ?> open orders</small><span class="stat-mark purple">฿</span></div>
        </div>
        <div class="dashboard-grid"><section class="panel chart-panel"><div class="panel-heading"><div><h2>Sales overview</h2><p>Revenue from completed sales · last 7 months</p></div><?= pill('Live data', 'pill-live') ?></div><div class="chart-wrap"><canvas id="salesChart" aria-label="Monthly sales chart"></canvas></div></section>
            <section class="panel"><div class="panel-heading"><div><h2>Stock health</h2><p>Products at or below minimum stock</p></div><a class="text-link" href="?page=inventory">View inventory →</a></div>
                <?php if (!$lowStock): ?><div class="empty-state"><span>✓</span><strong>Everything looks healthy</strong><small>No products are below their minimum level.</small></div><?php else: ?><div class="stock-list"><?php foreach ($lowStock as $product): ?><div class="stock-row"><div class="product-avatar"><?= e(strtoupper(substr($product['brand'], 0, 1))) ?></div><div class="stock-product"><strong><?= e($product['brand'] . ' ' . $product['model']) ?></strong><small><?= e($product['storage']) ?> · Min <?= (int) $product['minimum_stock'] ?></small></div><strong class="stock-number <?= (int) $product['stock'] === 0 ? 'text-danger' : 'text-warning' ?>"><?= (int) $product['stock'] ?> left</strong></div><?php endforeach; ?></div><?php endif; ?>
            </section>
            <section class="panel"><div class="panel-heading"><div><h2>Top selling models</h2><p>Lifetime completed units sold</p></div><a class="text-link" href="?page=reports">Reports →</a></div>
                <?php if (!$bestSellers): ?><div class="empty-state compact"><strong>No sales recorded yet</strong><small>Once you record sales, best sellers will appear here.</small></div><?php else: ?><div class="best-list"><?php foreach ($bestSellers as $index => $product): ?><div class="best-row"><span class="rank"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="best-name"><strong><?= e($product['brand'] . ' ' . $product['model']) ?></strong><small><?= (int) $product['units'] ?> units sold</small></span><span class="best-bar"><i style="width:<?= max(8, (int) round(((int) $product['units'] / max(1, (int) $bestSellers[0]['units'])) * 100)) ?>%"></i></span></div><?php endforeach; ?></div><?php endif; ?>
            </section>
            <section class="panel chart-panel"><div class="panel-heading"><div><h2>Sales by brand</h2><p>Completed units sold by brand</p></div></div><div class="chart-wrap chart-wrap-small"><canvas id="brandSalesChart" aria-label="Sales by brand chart"></canvas></div></section>
            <section class="panel chart-panel"><div class="panel-heading"><div><h2>Stock by brand</h2><p>Units currently on hand</p></div></div><div class="chart-wrap chart-wrap-small"><canvas id="stockChart" aria-label="Stock by brand chart"></canvas></div></section>
        </div>
        <script type="application/json" id="dashboard-data"><?= json_encode(['monthly' => $monthlyRows, 'brandSales' => $brandRows, 'stock' => $stockRows], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
        <?php
    } elseif ($page === 'products') {
        $search = trim((string) ($_GET['q'] ?? ''));
        $brand = trim((string) ($_GET['brand'] ?? ''));
        $filters = ['user_id' => $userId];
        $where = 'p.user_id=:user_id';
        if ($search !== '') {
            $where .= ' AND (p.sku LIKE :sku_search OR p.model LIKE :model_search OR p.brand LIKE :brand_search)';
            $filters['sku_search'] = '%' . $search . '%';
            $filters['model_search'] = '%' . $search . '%';
            $filters['brand_search'] = '%' . $search . '%';
        }
        if ($brand !== '') {
            $where .= ' AND p.brand=:brand';
            $filters['brand'] = $brand;
        }
        $query = $db->prepare("SELECT p.*,s.name supplier_name FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE {$where} ORDER BY p.created_at DESC");
        $query->execute($filters);
        $products = $query->fetchAll();
        $brandsQuery = $db->prepare('SELECT DISTINCT brand FROM products WHERE user_id=:user_id ORDER BY brand');
        $brandsQuery->execute(['user_id' => $userId]);
        $brands = $brandsQuery->fetchAll();
        $suppliersQuery = $db->prepare('SELECT id,name FROM suppliers WHERE user_id=:user_id ORDER BY name');
        $suppliersQuery->execute(['user_id' => $userId]);
        $suppliers = $suppliersQuery->fetchAll();
        $edit = null;
        if (isset($_GET['edit'])) {
            $editQuery = $db->prepare('SELECT * FROM products WHERE id=:id AND user_id=:user_id');
            $editQuery->execute(['id' => positive_int($_GET['edit'], 'Product'), 'user_id' => $userId]);
            $edit = $editQuery->fetch() ?: null;
        }
        echo page_heading('CATALOG', 'Products', 'Manage phone SKUs, pricing, supplier links, and stock thresholds.', '<a class="button button-primary" href="#product-form">＋ Add product</a>');
        ?><div class="panel filter-panel"><form method="get" class="filter-form"><input type="hidden" name="page" value="products"><label class="search-input"><span>⌕</span><input name="q" value="<?= e($search) ?>" placeholder="Search product, model, or SKU"></label><select name="brand"><option value="">All brands</option><?= option_list($brands, 'brand', 'brand', $brand) ?></select><button class="button button-secondary" type="submit">Filter</button><a class="button button-quiet" href="?page=products">Reset</a></form></div>
        <section class="panel table-panel"><div class="panel-heading"><div><h2>Product catalog</h2><p><?= count($products) ?> matching SKU<?= count($products) === 1 ? '' : 's' ?></p></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>SKU</th><th>PRICE</th><th>STOCK</th><th>SUPPLIER</th><th>ACTIONS</th></tr></thead><tbody>
        <?php foreach ($products as $product): ?><tr><td><div class="table-product"><?php if ($product['image_url']): ?><img class="product-image" src="<?= e($product['image_url']) ?>" alt="" loading="lazy"><?php else: ?><span class="product-avatar"><?= e(strtoupper(substr($product['brand'], 0, 1))) ?></span><?php endif; ?><span><strong><?= e($product['brand'] . ' ' . $product['model']) ?></strong><small><?= e($product['storage'] . ' · ' . ($product['ram'] ?: 'RAM n/a') . ' · ' . $product['color']) ?></small></span></div></td><td class="muted"><?= e($product['sku']) ?></td><td><strong>฿<?= e(money($product['selling_price'])) ?></strong><small class="subline">Cost ฿<?= e(money($product['cost_price'])) ?></small></td><td><?= (int) $product['stock'] <= (int) $product['minimum_stock'] ? pill((string) $product['stock'] . ' low', 'pill-warn') : pill((string) $product['stock'] . ' units', 'pill-ok') ?></td><td><?= e($product['supplier_name'] ?: '—') ?></td><td><div class="table-actions"><a class="text-link" href="?page=products&edit=<?= (int) $product['id'] ?>#product-form">Edit</a><?= form_open('product_delete', ['product_id' => $product['id']]) ?><button class="link-button danger-link" type="submit" data-confirm="Delete this product? Products with history cannot be deleted.">Delete</button></form></div></td></tr><?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="6"><div class="empty-state compact"><strong>No products found</strong><small>Add a SKU to start tracking your inventory.</small></div></td></tr><?php endif; ?>
        </tbody></table></div></section>
        <section class="panel form-panel" id="product-form"><div class="panel-heading"><div><h2><?= $edit ? 'Edit product' : 'Add a product' ?></h2><p>Each color and storage configuration should have its own SKU.</p></div></div>
        <?= form_open('product_save') ?><?php if ($edit): ?><input type="hidden" name="product_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
            <div class="form-grid"><label>SKU<input name="sku" required maxlength="80" value="<?= e($edit['sku'] ?? '') ?>" placeholder="APL-17-256-BLK"></label><label>Brand<input name="brand" required maxlength="100" value="<?= e($edit['brand'] ?? '') ?>" placeholder="Apple"></label><label>Model<input name="model" required maxlength="160" value="<?= e($edit['model'] ?? '') ?>" placeholder="iPhone 17"></label><label>Storage<input name="storage" required maxlength="40" value="<?= e($edit['storage'] ?? '') ?>" placeholder="256GB"></label><label>RAM<input name="ram" maxlength="40" value="<?= e($edit['ram'] ?? '') ?>" placeholder="8GB"></label><label>Color<input name="color" required maxlength="80" value="<?= e($edit['color'] ?? '') ?>" placeholder="Black"></label><label>Cost price (THB)<input name="cost_price" type="number" step="0.01" min="0" required value="<?= e($edit['cost_price'] ?? '') ?>"></label><label>Selling price (THB)<input name="selling_price" type="number" step="0.01" min="0" required value="<?= e($edit['selling_price'] ?? '') ?>"></label><label>Minimum stock<input name="minimum_stock" type="number" min="0" required value="<?= e($edit['minimum_stock'] ?? '0') ?>"></label><label>Safety stock<input name="safety_stock" type="number" min="0" required value="<?= e($edit['safety_stock'] ?? '0') ?>"></label><label>Lead time (days)<input name="lead_time_days" type="number" min="0" required value="<?= e($edit['lead_time_days'] ?? '7') ?>"></label><label>Supplier<select name="supplier_id"><option value="">No supplier assigned</option><?= option_list($suppliers, 'id', 'name', $edit['supplier_id'] ?? '') ?></select></label><label class="span-2">Product image URL<input name="image_url" type="url" maxlength="500" value="<?= e($edit['image_url'] ?? '') ?>" placeholder="https://example.com/image.jpg"><small>Optional; use an http or https image address.</small></label>
            <?php if (!$edit): ?><label>Initial stock<input name="stock" type="number" min="0" value="0" required></label><?php endif; ?></div><div class="form-footer"><button class="button button-primary" type="submit"><?= $edit ? 'Save changes' : 'Add product' ?></button><?php if ($edit): ?><a class="button button-quiet" href="?page=products">Cancel</a><?php endif; ?></div></form></section>
        <?php
    } elseif ($page === 'inventory') {
        $query = $db->prepare('SELECT id,sku,brand,model,storage,color,stock,minimum_stock FROM products WHERE user_id=:user_id ORDER BY (stock <= minimum_stock) DESC,brand,model');
        $query->execute(['user_id' => $userId]);
        $products = $query->fetchAll();
        $logs = $db->prepare('SELECT st.*,p.brand,p.model,p.sku FROM stock_transactions st JOIN products p ON p.id=st.product_id WHERE st.user_id=:user_id ORDER BY st.created_at DESC LIMIT 20');
        $logs->execute(['user_id' => $userId]);
        echo page_heading('STOCK CONTROL', 'Inventory', 'Record stock movements and keep an auditable history of every change.');
        ?><section class="panel table-panel"><div class="panel-heading"><div><h2>Current stock</h2><p>Stock alerts compare on-hand units with each SKU’s minimum.</p></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>ON HAND</th><th>MINIMUM</th><th>STOCK MOVEMENT</th></tr></thead><tbody>
        <?php foreach ($products as $product): ?><tr><td><div class="table-product"><span class="product-avatar"><?= e(strtoupper(substr($product['brand'], 0, 1))) ?></span><span><strong><?= e($product['brand'] . ' ' . $product['model']) ?></strong><small><?= e($product['sku'] . ' · ' . $product['storage'] . ' · ' . $product['color']) ?></small></span></div></td><td><?= (int) $product['stock'] <= (int) $product['minimum_stock'] ? pill((string) $product['stock'] . ' units', 'pill-warn') : '<strong>' . (int) $product['stock'] . ' units</strong>' ?></td><td><?= (int) $product['minimum_stock'] ?> units</td><td><?= form_open('stock_change', ['product_id' => $product['id']]) ?><div class="inline-stock"><select name="type" aria-label="Stock action"><option value="in">Stock in (+)</option><option value="out">Stock out (-)</option><option value="adjustment">Set counted stock</option></select><input name="quantity" type="number" min="0" value="1" required aria-label="Quantity or counted stock"><input name="notes" maxlength="200" placeholder="Reason / reference" aria-label="Notes"><button class="button button-secondary button-small" type="submit">Apply</button></div></form></td></tr><?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="4"><div class="empty-state compact"><strong>No products in inventory</strong><small>Add products before recording stock movements.</small></div></td></tr><?php endif; ?></tbody></table></div></section>
        <section class="panel table-panel"><div class="panel-heading"><div><h2>Recent stock transactions</h2><p>Latest 20 recorded movements</p></div></div><div class="table-scroll"><table><thead><tr><th>DATE</th><th>PRODUCT</th><th>TYPE</th><th>CHANGE</th><th>REFERENCE / NOTE</th></tr></thead><tbody><?php foreach ($logs->fetchAll() as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><?= e($log['brand'] . ' ' . $log['model']) ?><small class="subline"><?= e($log['sku']) ?></small></td><td><?= pill(ucfirst($log['type']), in_array($log['type'], ['in','purchase'], true) ? 'pill-ok' : 'pill-neutral') ?></td><td><?= (int) $log['quantity'] > 0 ? '+' : '' ?><?= (int) $log['quantity'] ?></td><td><?= e($log['notes'] ?: ($log['reference_type'] ? $log['reference_type'] . ' #' . $log['reference_id'] : '—')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php
    } elseif ($page === 'sales') {
        $productsQuery = $db->prepare('SELECT id,brand,model,storage,color,stock,selling_price FROM products WHERE user_id=:user_id ORDER BY brand,model');
        $productsQuery->execute(['user_id' => $userId]);
        $products = $productsQuery->fetchAll();
        $sales = $db->prepare("SELECT s.id,s.sale_date,s.payment_method,s.total_amount,s.status,COUNT(si.id) line_count,SUM(si.quantity) units FROM sales s LEFT JOIN sale_items si ON si.sale_id=s.id WHERE s.user_id=:user_id GROUP BY s.id ORDER BY s.sale_date DESC,s.id DESC LIMIT 50");
        $sales->execute(['user_id' => $userId]);
        echo page_heading('REVENUE', 'Sales', 'Record multi-item sales, automatically deduct stock, and review transaction history.');
        ?><section class="panel form-panel"><div class="panel-heading"><div><h2>Record a sale</h2><p>Each item is checked against available stock before the sale is committed.</p></div></div><?= form_open('sale_create') ?>
        <div class="form-grid form-grid-compact"><label>Sale date<input name="sale_date" type="date" value="<?= e(date('Y-m-d')) ?>" required></label><label>Payment method<select name="payment_method" required><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label></div>
        <div class="line-items" data-lines data-product-options="<?= e(product_options($products)) ?>" data-name-prefix="items"><div class="line-item"><label>Product<select name="items[0][product_id]" required><option value="">Select product</option><?= product_options($products) ?></select></label><label>Quantity<input name="items[0][quantity]" type="number" min="1" value="1" required></label><button class="icon-button remove-line" type="button" aria-label="Remove item" disabled>×</button></div></div><button class="button button-quiet add-line" type="button">＋ Add another item</button><div class="form-footer"><button class="button button-primary" type="submit">Complete sale</button></div></form></section>
        <section class="panel table-panel"><div class="panel-heading"><div><h2>Recent sales</h2><p>Most recent 50 receipts</p></div></div><div class="table-scroll"><table><thead><tr><th>SALE</th><th>DATE</th><th>ITEMS</th><th>PAYMENT</th><th>TOTAL</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody><?php foreach ($sales->fetchAll() as $sale): ?><tr><td>#<?= (int) $sale['id'] ?></td><td><?= e($sale['sale_date']) ?></td><td><?= (int) $sale['units'] ?> units · <?= (int) $sale['line_count'] ?> SKUs</td><td><?= e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></td><td><strong>฿<?= e(money($sale['total_amount'])) ?></strong></td><td><?= pill(ucfirst($sale['status']), $sale['status'] === 'completed' ? 'pill-ok' : 'pill-neutral') ?></td><td><?php if ($sale['status'] === 'completed'): ?><?= form_open('sale_cancel', ['sale_id' => $sale['id']]) ?><button class="link-button danger-link" type="submit" data-confirm="Cancel this sale and restore all sold quantities to stock?">Cancel sale</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php
    } elseif ($page === 'forecast') {
        $productsQuery = $db->prepare('SELECT id,brand,model,storage,color FROM products WHERE user_id=:user_id ORDER BY brand,model');
        $productsQuery->execute(['user_id' => $userId]);
        $products = $productsQuery->fetchAll();
        $selectedProduct = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
        $history = [];
        $comparison = null;
        $forecast = null;
        $selectedName = (string) ($_GET['model'] ?? '');
        $days = filter_var($_GET['days'] ?? 30, FILTER_VALIDATE_INT);
        if ($selectedProduct) {
            $validProduct = $db->prepare('SELECT id FROM products WHERE id=:id AND user_id=:user_id');
            $validProduct->execute(['id' => $selectedProduct, 'user_id' => $userId]);
            if ($validProduct->fetch()) {
                $service = new ForecastService($db, $userId);
                $history = $service->history($selectedProduct);
                $comparison = $service->compareModels($history);
                if ($selectedName !== '' && in_array($days, [7, 30, 90, 180], true)) {
                    $saved = $db->prepare('SELECT equation,forecast_data,created_at FROM forecast_results WHERE user_id=:user_id AND product_id=:product_id AND model_name=:model_name AND horizon_days=:days ORDER BY created_at DESC LIMIT 1');
                    $saved->execute(['user_id' => $userId, 'product_id' => $selectedProduct, 'model_name' => $selectedName, 'days' => $days]);
                    if ($row = $saved->fetch()) {
                        $forecast = ['equation' => $row['equation'], 'forecast' => json_decode($row['forecast_data'], true, 512, JSON_THROW_ON_ERROR), 'created_at' => $row['created_at']];
                    }
                }
            }
        }
        echo page_heading('DEMAND INTELLIGENCE', 'Regression & forecasting', 'Compare three regression approaches and forecast demand from your observed sales history.');
        ?><section class="panel form-panel"><div class="panel-heading"><div><h2>Build a forecast</h2><p>Missing sale dates remain unobserved; they are never automatically counted as zero sales.</p></div></div><?= form_open('forecast_run') ?>        <div class="form-grid form-grid-compact"><label>Product<select name="product_id" required><option value="">Select a product</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"<?= selected($selectedProduct, $product['id']) ?>><?= e($product['brand'] . ' ' . $product['model'] . ' · ' . $product['storage'] . ' · ' . $product['color']) ?></option><?php endforeach; ?></select></label><label>Regression model<select name="model" required><option value="Linear"<?= selected($selectedName, 'Linear') ?>>Linear regression</option><option value="Polynomial (degree 2)"<?= selected($selectedName, 'Polynomial (degree 2)') ?>>Polynomial (degree 2)</option><option value="Multiple linear"<?= selected($selectedName, 'Multiple linear') ?>>Multiple linear regression</option></select></label><label>Forecast period<select name="days"><option value="7"<?= selected($days, 7) ?>>7 days</option><option value="30"<?= selected($days, 30) ?>>30 days</option><option value="90"<?= selected($days, 90) ?>>3 months (90 days)</option><option value="180"<?= selected($days, 180) ?>>6 months (180 days)</option></select></label></div><div class="form-footer"><button class="button button-primary" type="submit">Calculate forecast <span>→</span></button></div></form></section>
        <?php if ($selectedProduct && $comparison): ?><section class="panel table-panel"><div class="panel-heading"><div><h2>Model evaluation</h2><p><?= count($history) ?> observed sale dates · chronological holdout split · no random sampling</p></div><?= $comparison['recommended'] ? pill('Recommended: ' . $comparison['recommended'], 'pill-live') : pill('Insufficient history', 'pill-warn') ?></div>
            <?php if (!$comparison['sufficient']): ?><div class="notice notice-warning"><?= e($comparison['message']) ?> No model recommendation is made until the data can be evaluated.</div><?php else: ?><p class="evaluation-note"><?= e($comparison['message']) ?></p><?php endif; ?>
            <div class="table-scroll"><table><thead><tr><th>MODEL</th><th>MAE</th><th>MSE</th><th>RMSE</th><th>R²</th><th>PERFORMANCE</th></tr></thead><tbody><?php foreach ($comparison['models'] as $name => $metrics): ?><tr><td><strong><?= e($name) ?></strong></td><?php if (isset($metrics['error'])): ?><td colspan="5" class="text-warning"><?= e($metrics['error']) ?></td><?php else: ?><td><?= e(number_format($metrics['mae'], 3)) ?></td><td><?= e(number_format($metrics['mse'], 3)) ?></td><td><?= e(number_format($metrics['rmse'], 3)) ?></td><td><?= e(number_format($metrics['r2'], 3)) ?></td><td><?= $name === $comparison['recommended'] ? pill('Best test MAE', 'pill-ok') : pill('Compared', 'pill-neutral') ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div>
        </section><?php endif; ?>
        <?php if ($forecast): ?><section class="panel chart-panel"><div class="panel-heading"><div><h2>Forecast result</h2><p><?= e($selectedName) ?> · <?= (int) $days ?> days · calculated <?= e($forecast['created_at']) ?></p></div><?= pill('Saved forecast', 'pill-live') ?></div><p class="equation"><?= e($forecast['equation']) ?></p><div class="chart-wrap"><canvas id="forecastChart"></canvas></div></section><script type="application/json" id="forecast-data"><?= json_encode(['history' => $history, 'forecast' => $forecast['forecast']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script><?php endif; ?>
        <?php if ($selectedProduct && !$history): ?><div class="notice notice-warning">No recorded sales for this SKU yet. A forecast needs real sales observations.</div><?php endif; ?>
        <?php
    } elseif ($page === 'recommendations') {
        $productQuery = $db->prepare('SELECT * FROM products WHERE user_id=:user_id ORDER BY brand,model');
        $productQuery->execute(['user_id' => $userId]);
        $recommendations = [];
        $advisor = new PurchaseAdvisor();
        foreach ($productQuery->fetchAll() as $product) {
            $service = new ForecastService($db, $userId);
            $history = $service->history((int) $product['id']);
            if (count($history) < 8) {
                $recommendations[] = ['product' => $product, 'unavailable' => true];
                continue;
            }
            $comparison = $service->compareModels($history);
            if (!$comparison['sufficient']) {
                $recommendations[] = ['product' => $product, 'unavailable' => true];
                continue;
            }
            $modelName = $comparison['recommended'];
            $forecast = $service->generateForecast($history, $modelName, max(1, min(365, (int) $product['lead_time_days'])));
            $demand = array_sum(array_column($forecast['forecast'], 'quantity'));
            $incomingQuery = $db->prepare("SELECT COALESCE(SUM(poi.quantity-poi.received_quantity),0) FROM purchase_order_items poi JOIN purchase_orders po ON po.id=poi.purchase_order_id WHERE po.user_id=:user_id AND po.status='ordered' AND poi.product_id=:product_id");
            $incomingQuery->execute(['user_id' => $userId, 'product_id' => $product['id']]);
            $incoming = (int) $incomingQuery->fetchColumn();
            $recommendations[] = [
                'product' => $product, 'unavailable' => false, 'model' => $modelName, 'demand' => $demand,
                'incoming' => $incoming,
                'advice' => $advisor->generateRecommendation($demand, (int) $product['safety_stock'], (int) $product['stock'], $incoming, (float) $product['cost_price']),
            ];
        }
        echo page_heading('REPLENISHMENT', 'Purchase advice', 'Forecast demand through supplier lead time, then account for safety stock and ordered incoming units.', '<a class="button button-primary" href="?page=purchase-orders">Create purchase order →</a>');
        ?><div class="notice notice-info">Recommendations are advisory only. No purchase order is created until you confirm it in Purchase orders.</div><section class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>LEAD-TIME DEMAND</th><th>ON HAND</th><th>INCOMING</th><th>RECOMMENDED</th><th>URGENCY</th><th>EST. COST</th></tr></thead><tbody><?php foreach ($recommendations as $row): $product = $row['product']; ?><tr><td><div class="table-product"><span class="product-avatar"><?= e(strtoupper(substr($product['brand'], 0, 1))) ?></span><span><strong><?= e($product['brand'] . ' ' . $product['model']) ?></strong><small><?= e($product['sku']) ?></small></span></div></td><?php if ($row['unavailable']): ?><td colspan="6" class="text-warning">Insufficient observed sales history to make a reliable forecast.</td><?php else: ?><td><?= e(number_format($row['demand'], 1)) ?> units<small class="subline"><?= e($row['model']) ?> · <?= (int) $product['lead_time_days'] ?> days</small></td><td><?= (int) $product['stock'] ?></td><td><?= (int) $row['incoming'] ?></td><td><strong><?= (int) $row['advice']['quantity'] ?> units</strong><small class="subline">Safety stock <?= (int) $product['safety_stock'] ?></small></td><td><?= pill($row['advice']['urgency'], $row['advice']['urgency'] === 'Urgent' || $row['advice']['urgency'] === 'High' ? 'pill-warn' : 'pill-neutral') ?></td><td>฿<?= e(money($row['advice']['estimated_cost'])) ?></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$recommendations): ?><tr><td colspan="7"><div class="empty-state compact"><strong>No products to evaluate</strong><small>Add products and record real sales to receive purchase advice.</small></div></td></tr><?php endif; ?></tbody></table></div></section>
        <?php
    } elseif ($page === 'suppliers') {
        $query = $db->prepare('SELECT s.*,COUNT(p.id) product_count FROM suppliers s LEFT JOIN products p ON p.supplier_id=s.id WHERE s.user_id=:user_id GROUP BY s.id ORDER BY s.name');
        $query->execute(['user_id' => $userId]);
        $suppliers = $query->fetchAll();
        $edit = null;
        if (isset($_GET['edit'])) {
            $editQuery = $db->prepare('SELECT * FROM suppliers WHERE id=:id AND user_id=:user_id');
            $editQuery->execute(['id' => positive_int($_GET['edit'], 'Supplier'), 'user_id' => $userId]);
            $edit = $editQuery->fetch() ?: null;
        }
        echo page_heading('PARTNERS', 'Suppliers', 'Keep supplier contacts connected to products and purchasing.');
        ?><div class="content-columns"><section class="panel form-panel"><div class="panel-heading"><div><h2><?= $edit ? 'Edit supplier' : 'Add supplier' ?></h2><p>Supplier details are private to your account.</p></div></div><?= form_open('supplier_save') ?><?php if ($edit): ?><input type="hidden" name="supplier_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?><div class="form-stack"><label>Supplier name<input name="name" required value="<?= e($edit['name'] ?? '') ?>"></label><label>Contact person<input name="contact_person" value="<?= e($edit['contact_person'] ?? '') ?>"></label><label>Phone<input name="phone" value="<?= e($edit['phone'] ?? '') ?>"></label><label>Email<input name="email" type="email" value="<?= e($edit['email'] ?? '') ?>"></label><label>Address<textarea name="address" rows="3"><?= e($edit['address'] ?? '') ?></textarea></label></div><div class="form-footer"><button class="button button-primary" type="submit"><?= $edit ? 'Save supplier' : 'Add supplier' ?></button><?php if ($edit): ?><a class="button button-quiet" href="?page=suppliers">Cancel</a><?php endif; ?></div></form></section>
        <section class="panel table-panel"><div class="panel-heading"><div><h2>Your suppliers</h2><p><?= count($suppliers) ?> supplier<?= count($suppliers) === 1 ? '' : 's' ?></p></div></div><div class="supplier-list"><?php foreach ($suppliers as $supplier): ?><div class="supplier-row"><span class="product-avatar avatar-purple"><?= e(strtoupper(substr($supplier['name'], 0, 1))) ?></span><div class="supplier-info"><strong><?= e($supplier['name']) ?></strong><small><?= e($supplier['contact_person'] ?: 'No contact person') ?> · <?= (int) $supplier['product_count'] ?> products</small><small><?= e($supplier['email'] ?: $supplier['phone'] ?: 'No contact details') ?></small></div><div class="table-actions"><a class="text-link" href="?page=suppliers&edit=<?= (int) $supplier['id'] ?>">Edit</a><?= form_open('supplier_delete', ['supplier_id' => $supplier['id']]) ?><button class="link-button danger-link" type="submit" data-confirm="Delete this supplier? Suppliers used in purchase orders cannot be deleted.">Delete</button></form></div></div><?php endforeach; ?><?php if (!$suppliers): ?><div class="empty-state"><strong>No suppliers yet</strong><small>Add supplier records to create purchase orders.</small></div><?php endif; ?></div></section></div>
        <?php
    } elseif ($page === 'purchase-orders') {
        $supQuery = $db->prepare('SELECT id,name FROM suppliers WHERE user_id=:user_id ORDER BY name');
        $supQuery->execute(['user_id' => $userId]);
        $suppliers = $supQuery->fetchAll();
        $prodQuery = $db->prepare('SELECT id,brand,model,storage,color,sku,cost_price FROM products WHERE user_id=:user_id ORDER BY brand,model');
        $prodQuery->execute(['user_id' => $userId]);
        $products = $prodQuery->fetchAll();
        $orders = $db->prepare('SELECT po.*,s.name supplier_name,COUNT(poi.id) line_count,SUM(poi.quantity) units FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN purchase_order_items poi ON poi.purchase_order_id=po.id WHERE po.user_id=:user_id GROUP BY po.id ORDER BY po.created_at DESC LIMIT 50');
        $orders->execute(['user_id' => $userId]);
        echo page_heading('PROCUREMENT', 'Purchase orders', 'Create drafts, confirm orders with suppliers, and receive stock once deliveries arrive.');
        ?><section class="panel form-panel"><div class="panel-heading"><div><h2>Create a purchase order</h2><p>Orders start as drafts. Set the status to Ordered to count incoming stock.</p></div></div><?php if (!$suppliers || !$products): ?><div class="notice notice-warning">Add at least one supplier and one product before creating a purchase order.</div><?php else: ?><?= form_open('po_create') ?><div class="form-grid form-grid-compact"><label>Supplier<select name="supplier_id" required><option value="">Select supplier</option><?= option_list($suppliers, 'id', 'name') ?></select></label></div><div class="line-items" data-lines data-product-options="<?= e(product_options($products)) ?>" data-name-prefix="items"><div class="line-item"><label>Product<select name="items[0][product_id]" required><option value="">Select product</option><?= product_options($products) ?></select></label><label>Quantity<input name="items[0][quantity]" type="number" min="1" value="1" required></label><button class="icon-button remove-line" type="button" aria-label="Remove item" disabled>×</button></div></div><button class="button button-quiet add-line" type="button">＋ Add another item</button><div class="form-footer"><button class="button button-primary" type="submit">Create draft order</button></div></form><?php endif; ?></section>
        <section class="panel table-panel"><div class="panel-heading"><div><h2>Order history</h2><p>Latest 50 purchase orders</p></div></div><div class="table-scroll"><table><thead><tr><th>ORDER</th><th>SUPPLIER</th><th>CREATED</th><th>ITEMS</th><th>TOTAL COST</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody><?php foreach ($orders->fetchAll() as $order): ?><tr><td>#<?= (int) $order['id'] ?></td><td><?= e($order['supplier_name']) ?></td><td><?= e(substr($order['created_at'], 0, 10)) ?></td><td><?= (int) $order['units'] ?> units · <?= (int) $order['line_count'] ?> SKUs</td><td>฿<?= e(money($order['total_cost'])) ?></td><td><?= pill(ucfirst($order['status']), $order['status'] === 'received' ? 'pill-ok' : ($order['status'] === 'cancelled' ? 'pill-neutral' : 'pill-warn')) ?></td><td><?php if (in_array($order['status'], ['draft','pending'], true)): ?><?= form_open('po_status', ['order_id' => $order['id']]) ?><input type="hidden" name="status" value="pending"><button class="link-button" type="submit">Mark pending</button></form> <?= form_open('po_status', ['order_id' => $order['id']]) ?><input type="hidden" name="status" value="ordered"><button class="link-button" type="submit" data-confirm="Confirm this order with the supplier?">Mark ordered</button></form> <?= form_open('po_status', ['order_id' => $order['id']]) ?><input type="hidden" name="status" value="cancelled"><button class="link-button danger-link" type="submit" data-confirm="Cancel this purchase order?">Cancel</button></form><?php elseif ($order['status'] === 'ordered'): ?><?= form_open('po_receive', ['order_id' => $order['id']]) ?><button class="link-button" type="submit" data-confirm="Receive this complete order and add its quantities to stock? This can only happen once.">Receive order</button></form> <?= form_open('po_status', ['order_id' => $order['id']]) ?><input type="hidden" name="status" value="cancelled"><button class="link-button danger-link" type="submit" data-confirm="Cancel this purchase order?">Cancel</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php
    } elseif ($page === 'reports') {
        $byProduct = $db->prepare("SELECT p.brand,p.model,p.sku,SUM(si.quantity) units,SUM(si.line_total) revenue FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE p.user_id=:user_id AND s.status='completed' GROUP BY p.id ORDER BY revenue DESC LIMIT 100");
        $byProduct->execute(['user_id' => $userId]);
        $daily = $db->prepare("SELECT sale_date,COUNT(*) receipts,SUM(total_amount) revenue FROM sales WHERE user_id=:user_id AND status='completed' GROUP BY sale_date ORDER BY sale_date DESC LIMIT 30");
        $daily->execute(['user_id' => $userId]);
        echo page_heading('BUSINESS INSIGHTS', 'Reports', 'Sales performance by product and date, based on completed transactions.');
        ?><section class="panel table-panel"><div class="panel-heading"><div><h2>Sales by product</h2><p>Lifetime units and revenue</p></div></div><div class="table-scroll"><table><thead><tr><th>PRODUCT</th><th>SKU</th><th>UNITS SOLD</th><th>REVENUE</th></tr></thead><tbody><?php foreach ($byProduct->fetchAll() as $row): ?><tr><td><?= e($row['brand'] . ' ' . $row['model']) ?></td><td><?= e($row['sku']) ?></td><td><?= (int) $row['units'] ?></td><td>฿<?= e(money($row['revenue'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <section class="panel table-panel"><div class="panel-heading"><div><h2>Daily sales</h2><p>Latest 30 dates with observed sales (dates without sales are not included).</p></div></div><div class="table-scroll"><table><thead><tr><th>DATE</th><th>RECEIPTS</th><th>REVENUE</th></tr></thead><tbody><?php foreach ($daily->fetchAll() as $row): ?><tr><td><?= e($row['sale_date']) ?></td><td><?= (int) $row['receipts'] ?></td><td>฿<?= e(money($row['revenue'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php
    } else {
        echo page_heading('PREFERENCES', 'Settings', 'Your account and workspace access.');
        ?><section class="panel settings-card"><div class="settings-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div><div><div class="eyebrow">SIGNED IN AS</div><h2><?= e($user['name']) ?></h2><p><?= e($user['email']) ?></p><small>All products, sales, and suppliers are scoped to this account.</small></div></section><?php
    }

    $content = ob_get_clean();
    page_layout($title, $content, $user);
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log((string) $exception);
    http_response_code(500);
    $message = $exception instanceof PDOException || $exception->getPrevious() instanceof PDOException
        ? 'Unable to load the application data. Check your MySQL connection and import database/database.sql.'
        : 'Something went wrong while loading this page.';
    page_layout('Application error', '<main class="error-page"><div class="error-code">500</div><h1>We could not load this page.</h1><p>' . e($message) . '</p><a class="button button-primary" href="?">Try again</a></main>', $_SESSION['user'] ?? null);
}
