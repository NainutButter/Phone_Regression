<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class SalesService
{
    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
    }

    public function createSale(array $items, string $paymentMethod, string $saleDate): int
    {
        if (!$items || !in_array($paymentMethod, ['cash', 'card', 'bank_transfer', 'other'], true)) {
            throw new \InvalidArgumentException('Add at least one product and choose a payment method.');
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $saleDate);
        if (!$date || $date->format('Y-m-d') !== $saleDate) {
            throw new \InvalidArgumentException('Enter a valid sale date.');
        }

        $normalized = [];
        foreach ($items as $item) {
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1) {
                throw new \InvalidArgumentException('Each sale line needs a product and a positive quantity.');
            }
            $normalized[$productId] = ($normalized[$productId] ?? 0) + $quantity;
        }

        $this->db->beginTransaction();
        try {
            ksort($normalized);
            $products = [];
            $select = $this->db->prepare('SELECT id, model, selling_price, stock FROM products WHERE id = :id AND user_id = :user_id FOR UPDATE');
            foreach ($normalized as $productId => $quantity) {
                $select->execute(['id' => $productId, 'user_id' => $this->userId]);
                $product = $select->fetch();
                if (!$product) {
                    throw new RuntimeException('A selected product could not be found.');
                }
                if ((int) $product['stock'] < $quantity) {
                    throw new RuntimeException("Insufficient stock for {$product['model']}.");
                }
                $products[$productId] = $product;
            }

            $sale = $this->db->prepare(
                'INSERT INTO sales (user_id, payment_method, sale_date, total_amount) VALUES (:user_id, :payment_method, :sale_date, 0)'
            );
            $sale->execute(['user_id' => $this->userId, 'payment_method' => $paymentMethod, 'sale_date' => $saleDate]);
            $saleId = (int) $this->db->lastInsertId();
            $line = $this->db->prepare(
                'INSERT INTO sale_items (sale_id, product_id, quantity, selling_price, line_total) VALUES (:sale_id, :product_id, :quantity, :selling_price, :line_total)'
            );
            $stock = $this->db->prepare('UPDATE products SET stock = stock - :quantity WHERE id = :id AND user_id = :user_id');
            $log = $this->db->prepare(
                "INSERT INTO stock_transactions (user_id, product_id, type, quantity, reference_type, reference_id, notes) VALUES (:user_id, :product_id, 'sale', :quantity, 'sale', :sale_id, 'Sale stock-out')"
            );
            $total = 0.0;
            foreach ($normalized as $productId => $quantity) {
                $price = (float) $products[$productId]['selling_price'];
                $lineTotal = $price * $quantity;
                $total += $lineTotal;
                $line->execute(['sale_id' => $saleId, 'product_id' => $productId, 'quantity' => $quantity, 'selling_price' => $price, 'line_total' => $lineTotal]);
                $stock->execute(['quantity' => $quantity, 'id' => $productId, 'user_id' => $this->userId]);
                $log->execute(['user_id' => $this->userId, 'product_id' => $productId, 'quantity' => -$quantity, 'sale_id' => $saleId]);
            }
            $update = $this->db->prepare('UPDATE sales SET total_amount = :total WHERE id = :id AND user_id = :user_id');
            $update->execute(['total' => $total, 'id' => $saleId, 'user_id' => $this->userId]);
            $this->db->commit();
            return $saleId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelSale(int $saleId): void
    {
        $this->db->beginTransaction();
        try {
            $saleQuery = $this->db->prepare('SELECT id, status FROM sales WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $saleQuery->execute(['id' => $saleId, 'user_id' => $this->userId]);
            $sale = $saleQuery->fetch();
            if (!$sale) {
                throw new RuntimeException('Sale not found.');
            }
            if ($sale['status'] !== 'completed') {
                throw new RuntimeException('This sale has already been cancelled.');
            }
            $itemsQuery = $this->db->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id = :sale_id ORDER BY product_id FOR UPDATE');
            $itemsQuery->execute(['sale_id' => $saleId]);
            $items = $itemsQuery->fetchAll();
            $restore = $this->db->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :id AND user_id = :user_id');
            $log = $this->db->prepare(
                "INSERT INTO stock_transactions (user_id, product_id, type, quantity, reference_type, reference_id, notes) VALUES (:user_id, :product_id, 'in', :quantity, 'sale_cancel', :sale_id, 'Sale cancelled; stock restored')"
            );
            foreach ($items as $item) {
                $restore->execute(['quantity' => $item['quantity'], 'id' => $item['product_id'], 'user_id' => $this->userId]);
                $log->execute(['user_id' => $this->userId, 'product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'sale_id' => $saleId]);
            }
            $update = $this->db->prepare("UPDATE sales SET status = 'cancelled' WHERE id = :id AND user_id = :user_id");
            $update->execute(['id' => $saleId, 'user_id' => $this->userId]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
