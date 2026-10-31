USE smartphone_stock;

-- Register an account in the application first, then set its email below.
SET @seed_email = 'your-account@example.com';
SET @seed_user_id = (SELECT id FROM users WHERE email = @seed_email LIMIT 1);

INSERT INTO suppliers (user_id, name, contact_person, phone, email, address)
SELECT @seed_user_id, 'Demo Mobile Distributor', 'Sales Team', '+66 2 000 0000', 'sales@example.test', 'Bangkok'
WHERE @seed_user_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM suppliers WHERE user_id = @seed_user_id AND name = 'Demo Mobile Distributor');

SET @seed_supplier_id = (
    SELECT id FROM suppliers
    WHERE user_id = @seed_user_id AND name = 'Demo Mobile Distributor'
    LIMIT 1
);

INSERT INTO products
    (user_id, supplier_id, sku, brand, model, storage, ram, color, cost_price, selling_price, stock, minimum_stock, safety_stock, lead_time_days)
SELECT @seed_user_id, @seed_supplier_id, demo.sku, demo.brand, demo.model, demo.storage, demo.ram, demo.color,
       demo.cost_price, demo.selling_price, demo.stock, demo.minimum_stock, demo.safety_stock, demo.lead_time_days
FROM (
    SELECT 'APL-17-256-BLK' sku, 'Apple' brand, 'iPhone 17' model, '256GB' storage, '8GB' ram, 'Black' color, 29000.00 cost_price, 35900.00 selling_price, 12 stock, 4 minimum_stock, 5 safety_stock, 14 lead_time_days
    UNION ALL SELECT 'SAM-S26-256-BLK', 'Samsung', 'Galaxy S26', '256GB', '12GB', 'Black', 27000.00, 33900.00, 8, 3, 5, 10
    UNION ALL SELECT 'XIA-17P-512-GRY', 'Xiaomi', '17 Pro', '512GB', '12GB', 'Gray', 22000.00, 27900.00, 5, 2, 4, 9
) demo
WHERE @seed_user_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM products p WHERE p.user_id = @seed_user_id AND p.sku = demo.sku);
