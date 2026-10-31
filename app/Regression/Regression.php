<?php
declare(strict_types=1);

namespace App\Regression;

abstract class Regression implements RegressionModel
{
    protected array $coefficients = [];

    abstract public function calculate(array $x, array $y): void;

    abstract public function predict(array|float|int $x): float;

    abstract public function equation(): string;

    protected function validate(array $x, array $y): void
    {
        if (count($x) !== count($y) || count($y) < 2) {
            throw new \InvalidArgumentException('At least two matching observations are required.');
        }
        foreach ($y as $value) {
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('Regression values must be finite numbers.');
            }
        }
    }

    protected function solve(array $matrix, array $vector): array
    {
        $n = count($vector);
        for ($column = 0; $column < $n; $column++) {
            $pivot = $column;
            for ($row = $column + 1; $row < $n; $row++) {
                if (abs($matrix[$row][$column]) > abs($matrix[$pivot][$column])) {
                    $pivot = $row;
                }
            }
            if (abs($matrix[$pivot][$column]) < 1e-12) {
                throw new \RuntimeException('Regression could not be calculated: singular matrix.');
            }
            [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];
            [$vector[$column], $vector[$pivot]] = [$vector[$pivot], $vector[$column]];

            $divisor = $matrix[$column][$column];
            for ($j = $column; $j < $n; $j++) {
                $matrix[$column][$j] /= $divisor;
            }
            $vector[$column] /= $divisor;

            for ($row = 0; $row < $n; $row++) {
                if ($row === $column) {
                    continue;
                }
                $factor = $matrix[$row][$column];
                for ($j = $column; $j < $n; $j++) {
                    $matrix[$row][$j] -= $factor * $matrix[$column][$j];
                }
                $vector[$row] -= $factor * $vector[$column];
            }
        }
        return $vector;
    }
}
