<?php
declare(strict_types=1);

namespace App\Services;

final class PurchaseAdvisor
{
    public function calculateRecommendedQuantity(float $forecastedDemand, int $safetyStock, int $currentStock, int $confirmedIncomingStock): int
    {
        return max(0, (int) ceil($forecastedDemand) + $safetyStock - $currentStock - $confirmedIncomingStock);
    }

    public function generateRecommendation(
        float $forecastedDemand,
        int $safetyStock,
        int $currentStock,
        int $confirmedIncomingStock,
        float $unitCost
    ): array {
        $quantity = $this->calculateRecommendedQuantity($forecastedDemand, $safetyStock, $currentStock, $confirmedIncomingStock);
        return [
            'quantity' => $quantity,
            'estimated_cost' => $quantity * $unitCost,
            'urgency' => $currentStock <= 0 ? 'Urgent' : ($currentStock <= $safetyStock ? 'High' : ($quantity > 0 ? 'Normal' : 'No order needed')),
        ];
    }
}
