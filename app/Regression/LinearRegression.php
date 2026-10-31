<?php
declare(strict_types=1);

namespace App\Regression;

final class LinearRegression extends Regression
{
    public function calculate(array $x, array $y): void
    {
        $this->validate($x, $y);
        $n = count($x);
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0.0;
        $sumX2 = 0.0;
        foreach ($x as $index => $value) {
            $sumXY += (float) $value * (float) $y[$index];
            $sumX2 += (float) $value ** 2;
        }
        $denominator = $n * $sumX2 - $sumX ** 2;
        if (abs($denominator) < 1e-12) {
            throw new \RuntimeException('Linear regression requires distinct x values.');
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        $intercept = ($sumY - $slope * $sumX) / $n;
        $this->coefficients = [$intercept, $slope];
    }

    public function predict(array|float|int $x): float
    {
        return $this->coefficients[0] + $this->coefficients[1] * (float) $x;
    }

    public function equation(): string
    {
        return sprintf('y = %.4f %+.4fx', $this->coefficients[0], $this->coefficients[1]);
    }
}
