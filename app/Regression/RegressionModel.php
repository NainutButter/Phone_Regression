<?php
declare(strict_types=1);

namespace App\Regression;

interface RegressionModel
{
    public function calculate(array $x, array $y): void;

    public function predict(array|float|int $x): float;

    public function equation(): string;
}
