<?php
declare(strict_types=1);

namespace App\Regression;

final class MultipleRegression extends Regression
{
    public function calculate(array $x, array $y): void
    {
        $this->validate($x, $y);
        $featureCount = count($x[0] ?? []);
        if ($featureCount < 1 || count($x) < $featureCount + 2) {
            throw new \InvalidArgumentException('Multiple regression has insufficient observations.');
        }
        foreach ($x as $row) {
            if (!is_array($row) || count($row) !== $featureCount) {
                throw new \InvalidArgumentException('All observations must have the same features.');
            }
        }
        $matrixSize = $featureCount + 1;
        $matrix = array_fill(0, $matrixSize, array_fill(0, $matrixSize, 0.0));
        $vector = array_fill(0, $matrixSize, 0.0);
        foreach ($x as $index => $features) {
            $row = [1.0, ...array_map('floatval', $features)];
            for ($i = 0; $i < $matrixSize; $i++) {
                $vector[$i] += $row[$i] * (float) $y[$index];
                for ($j = 0; $j < $matrixSize; $j++) {
                    $matrix[$i][$j] += $row[$i] * $row[$j];
                }
            }
        }
        $this->coefficients = $this->solve($matrix, $vector);
    }

    public function predict(array|float|int $x): float
    {
        if (!is_array($x) || count($x) + 1 !== count($this->coefficients)) {
            throw new \InvalidArgumentException('Prediction feature count does not match model.');
        }
        $prediction = $this->coefficients[0];
        foreach ($x as $index => $feature) {
            $prediction += $this->coefficients[$index + 1] * (float) $feature;
        }
        return $prediction;
    }

    public function equation(): string
    {
        $parts = [sprintf('%.4f', $this->coefficients[0])];
        foreach (array_slice($this->coefficients, 1) as $index => $coefficient) {
            $parts[] = sprintf('%+.4fx%d', $coefficient, $index + 1);
        }
        return 'y = ' . implode(' ', $parts);
    }
}
