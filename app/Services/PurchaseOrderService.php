<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class PurchaseOrderService
{
    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
    }

    public function create(int $supplierId, array $items): int
    {
        if (!$items) {
            throw new \InvalidArgumentException('Add at least one product to the purchase order.');
        }
        $normalized = [];
        foreach ($items as $item) {
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1) {
                throw new \InvalidArgumentException('Each purchase line needs a product and a positive quantity.');
            }
            $normalized[$productId] = ($normalized[$productId] ?? 0) + $quantity;
        }

        $this->db->beginTransaction();
        try {
            $supplier = $this->db->prepare('SELECT id FROM suppliers WHERE id = :id AND user_id = :user_id');
            $supplier->execute(['id' => $supplierId, 'user_id' => $this->userId]);
            if (!$supplier->fetch()) {
                throw new RuntimeException('Supplier not found.');
            }
            ksort($normalized);
            $productQuery = $this->db->prepare('SELECT id, cost_price FROM products WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $products = [];
            foreach ($normalized as $productId => $quantity) {
                $productQuery->execute(['id' => $productId, 'user_id' => $this->userId]);
                $product = $productQuery->fetch();
                if (!$product) {
                    throw new RuntimeException('A selected product could not be found.');
                }
                $products[$productId] = $product;
            }
            $order = $this->db->prepare("INSERT INTO purchase_orders (user_id, supplier_id, status, total_cost) VALUES (:user_id, :supplier_id, 'draft', 0)");
            $order->execute(['user_id' => $this->userId, 'supplier_id' => $supplierId]);
            $orderId = (int) $this->db->lastInsertId();
            $line = $this->db->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost) VALUES (:order_id, :product_id, :quantity, :unit_cost)');
            $total = 0.0;
            foreach ($normalized as $productId => $quantity) {
                $unitCost = (float) $products[$productId]['cost_price'];
                $line->execute(['order_id' => $orderId, 'product_id' => $productId, 'quantity' => $quantity, 'unit_cost' => $unitCost]);
                $total += $unitCost * $quantity;
            }
            $update = $this->db->prepare('UPDATE purchase_orders SET total_cost = :total WHERE id = :id AND user_id = :user_id');
            $update->execute(['total' => $total, 'id' => $orderId, 'user_id' => $this->userId]);
            $this->db->commit();
            return $orderId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function setStatus(int $orderId, string $status): void
    {
        if (!in_array($status, ['pending', 'ordered', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid purchase order status.');
        }
        $query = $this->db->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id AND user_id = :user_id AND status IN (\'draft\', \'pending\', \'ordered\')');
        $query->execute(['status' => $status, 'id' => $orderId, 'user_id' => $this->userId]);
        if ($query->rowCount() !== 1) {
            $current = $this->db->prepare('SELECT status FROM purchase_orders WHERE id = :id AND user_id = :user_id');
            $current->execute(['id' => $orderId, 'user_id' => $this->userId]);
            if ($current->fetchColumn() === $status) {
                return;
            }
            throw new RuntimeException('The purchase order status cannot be changed.');
        }
    }

    public function receive(int $orderId): void
    {
        $this->db->beginTransaction();
        try {
            $orderQuery = $this->db->prepare('SELECT id, status FROM purchase_orders WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $orderQuery->execute(['id' => $orderId, 'user_id' => $this->userId]);
            $order = $orderQuery->fetch();
            if (!$order || $order['status'] !== 'ordered') {
                throw new RuntimeException('Only an ordered purchase order can be received, and it can only be received once.');
            }
            $itemsQuery = $this->db->prepare('SELECT poi.product_id, poi.quantity FROM purchase_order_items poi WHERE poi.purchase_order_id = :order_id ORDER BY poi.product_id FOR UPDATE');
            $itemsQuery->execute(['order_id' => $orderId]);
            $items = $itemsQuery->fetchAll();
            $stock = $this->db->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :id AND user_id = :user_id');
            $received = $this->db->prepare('UPDATE purchase_order_items SET received_quantity = quantity WHERE purchase_order_id = :order_id AND product_id = :product_id');
            $log = $this->db->prepare(
                "INSERT INTO stock_transactions (user_id, product_id, type, quantity, reference_type, reference_id, notes) VALUES (:user_id, :product_id, 'purchase', :quantity, 'purchase_order', :order_id, 'Purchase order received')"
            );
            foreach ($items as $item) {
                $stock->execute(['quantity' => $item['quantity'], 'id' => $item['product_id'], 'user_id' => $this->userId]);
                $received->execute(['order_id' => $orderId, 'product_id' => $item['product_id']]);
                $log->execute(['user_id' => $this->userId, 'product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'order_id' => $orderId]);
            }
            $update = $this->db->prepare("UPDATE purchase_orders SET status = 'received', received_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id");
            $update->execute(['id' => $orderId, 'user_id' => $this->userId]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
