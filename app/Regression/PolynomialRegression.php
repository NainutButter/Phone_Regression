<?php
declare(strict_types=1);

namespace App\Regression;

final class PolynomialRegression extends Regression
{
    public function __construct(private readonly int $degree = 2)
    {
        if ($degree < 2 || $degree > 3) {
            throw new \InvalidArgumentException('Polynomial degree must be 2 or 3.');
        }
    }

    public function calculate(array $x, array $y): void
    {
        $this->validate($x, $y);
        $terms = $this->degree + 1;
        if (count($x) < $terms) {
            throw new \InvalidArgumentException("Degree {$this->degree} regression requires at least {$terms} observations.");
        }

        $matrix = array_fill(0, $terms, array_fill(0, $terms, 0.0));
        $vector = array_fill(0, $terms, 0.0);
        foreach ($x as $index => $value) {
            $powers = [1.0];
            for ($power = 1; $power <= $this->degree * 2; $power++) {
                $powers[$power] = $powers[$power - 1] * (float) $value;
            }
            for ($row = 0; $row < $terms; $row++) {
                $vector[$row] += $powers[$row] * (float) $y[$index];
                for ($column = 0; $column < $terms; $column++) {
                    $matrix[$row][$column] += $powers[$row + $column];
                }
            }
        }
        $this->coefficients = $this->solve($matrix, $vector);
    }

    public function predict(array|float|int $x): float
    {
        $value = (float) $x;
        $prediction = 0.0;
        foreach ($this->coefficients as $power => $coefficient) {
            $prediction += $coefficient * ($value ** $power);
        }
        return $prediction;
    }

    public function equation(): string
    {
        $parts = [];
        foreach ($this->coefficients as $power => $coefficient) {
            $parts[] = sprintf('%+.4f%s', $coefficient, $power === 0 ? '' : ($power === 1 ? 'x' : 'x^' . $power));
        }
        return 'y = ' . implode(' ', $parts);
    }
}
