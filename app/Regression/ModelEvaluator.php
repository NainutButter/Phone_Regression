<?php
declare(strict_types=1);

namespace App\Regression;

final class ModelEvaluator
{
    public function calculateMAE(array $actual, array $predicted): float
    {
        $this->validate($actual, $predicted);
        return array_sum(array_map(static fn ($a, $p) => abs($a - $p), $actual, $predicted)) / count($actual);
    }

    public function calculateMSE(array $actual, array $predicted): float
    {
        $this->validate($actual, $predicted);
        return array_sum(array_map(static fn ($a, $p) => ($a - $p) ** 2, $actual, $predicted)) / count($actual);
    }

    public function calculateRMSE(array $actual, array $predicted): float
    {
        return sqrt($this->calculateMSE($actual, $predicted));
    }

    public function calculateR2(array $actual, array $predicted): float
    {
        $this->validate($actual, $predicted);
        $mean = array_sum($actual) / count($actual);
        $total = array_sum(array_map(static fn ($a) => ($a - $mean) ** 2, $actual));
        if ($total < 1e-12) {
            return 0.0;
        }
        $residual = array_sum(array_map(static fn ($a, $p) => ($a - $p) ** 2, $actual, $predicted));
        return 1 - $residual / $total;
    }

    private function validate(array $actual, array $predicted): void
    {
        if (!$actual || count($actual) !== count($predicted)) {
            throw new \InvalidArgumentException('Actual and predicted values must have matching observations.');
        }
    }
}
