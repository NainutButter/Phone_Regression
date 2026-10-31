<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class InventoryService
{
    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
    }

    public function changeStock(int $productId, int $quantity, string $type, string $notes = ''): void
    {
        if ($quantity < 1 || !in_array($type, ['in', 'out'], true)) {
            throw new \InvalidArgumentException('Choose a valid stock action and positive quantity.');
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT id, stock FROM products WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $query->execute(['id' => $productId, 'user_id' => $this->userId]);
            $product = $query->fetch();
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }
            $delta = $type === 'in' ? $quantity : -$quantity;
            if ((int) $product['stock'] + $delta < 0) {
                throw new RuntimeException('Not enough stock available for that stock-out.');
            }
            $update = $this->db->prepare('UPDATE products SET stock = stock + :delta WHERE id = :id AND user_id = :user_id');
            $update->execute(['delta' => $delta, 'id' => $productId, 'user_id' => $this->userId]);
            $transaction = $this->db->prepare(
                'INSERT INTO stock_transactions (user_id, product_id, type, quantity, notes) VALUES (:user_id, :product_id, :type, :quantity, :notes)'
            );
            $transaction->execute([
                'user_id' => $this->userId,
                'product_id' => $productId,
                'type' => $type,
                'quantity' => $delta,
                'notes' => trim($notes) ?: null,
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function adjustStock(int $productId, int $newStock, string $notes = ''): void
    {
        if ($newStock < 0) {
            throw new \InvalidArgumentException('Counted stock cannot be negative.');
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT id, stock FROM products WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $query->execute(['id' => $productId, 'user_id' => $this->userId]);
            $product = $query->fetch();
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }
            $delta = $newStock - (int) $product['stock'];
            $update = $this->db->prepare('UPDATE products SET stock = :stock WHERE id = :id AND user_id = :user_id');
            $update->execute(['stock' => $newStock, 'id' => $productId, 'user_id' => $this->userId]);
            $transaction = $this->db->prepare(
                "INSERT INTO stock_transactions (user_id, product_id, type, quantity, notes) VALUES (:user_id, :product_id, 'adjustment', :quantity, :notes)"
            );
            $transaction->execute([
                'user_id' => $this->userId,
                'product_id' => $productId,
                'quantity' => $delta,
                'notes' => trim($notes) ?: 'Physical stock count',
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
