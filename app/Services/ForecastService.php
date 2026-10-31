<?php
declare(strict_types=1);

namespace App\Services;

use App\Regression\LinearRegression;
use App\Regression\ModelEvaluator;
use App\Regression\MultipleRegression;
use App\Regression\PolynomialRegression;
use PDO;

final class ForecastService
{
    private ModelEvaluator $evaluator;

    public function __construct(private readonly PDO $db, private readonly int $userId)
    {
        $this->evaluator = new ModelEvaluator();
    }

    public function history(int $productId): array
    {
        $query = $this->db->prepare(
            'SELECT s.sale_date, SUM(si.quantity) AS quantity, SUM(si.line_total) / SUM(si.quantity) AS average_price
             FROM sales s JOIN sale_items si ON si.sale_id = s.id
             JOIN products p ON p.id = si.product_id
             WHERE p.user_id = :user_id AND p.id = :product_id AND s.status = \'completed\'
             GROUP BY s.sale_date ORDER BY s.sale_date'
        );
        $query->execute(['user_id' => $this->userId, 'product_id' => $productId]);
        return $query->fetchAll();
    }

    public function compareModels(array $history): array
    {
        $count = count($history);
        if ($count < 8) {
            return ['sufficient' => false, 'models' => [], 'recommended' => null, 'message' => 'At least 8 observed sale dates are required for a basic time-ordered test.'];
        }

        $testCount = max(2, (int) ceil($count * 0.25));
        $split = $count - $testCount;
        if ($split < 5) {
            return ['sufficient' => false, 'models' => [], 'recommended' => null, 'message' => 'Not enough observations remain for both training and testing.'];
        }
        $dates = array_column($history, 'sale_date');
        $values = array_map('floatval', array_column($history, 'quantity'));
        $prices = array_map('floatval', array_column($history, 'average_price'));
        $origin = new \DateTimeImmutable($dates[0]);
        $x = array_map(static fn ($date) => (float) $origin->diff(new \DateTimeImmutable($date))->days, $dates);
        $results = [];
        $definitions = [
            'Linear' => static fn () => new LinearRegression(),
            'Polynomial (degree 2)' => static fn () => new PolynomialRegression(2),
            'Multiple linear' => static fn () => new MultipleRegression(),
        ];
        foreach ($definitions as $name => $factory) {
            try {
                $model = $factory();
                $trainingX = $name === 'Multiple linear'
                    ? array_map(static fn ($index) => [$x[$index], $prices[$index]], range(0, $split - 1))
                    : array_slice($x, 0, $split);
                $model->calculate($trainingX, array_slice($values, 0, $split));
                $predictions = [];
                for ($i = $split; $i < $count; $i++) {
                    $features = $name === 'Multiple linear' ? [$x[$i], $prices[$i]] : $x[$i];
                    $predictions[] = max(0.0, $model->predict($features));
                }
                $actual = array_slice($values, $split);
                $results[$name] = [
                    'mae' => $this->evaluator->calculateMAE($actual, $predictions),
                    'mse' => $this->evaluator->calculateMSE($actual, $predictions),
                    'rmse' => $this->evaluator->calculateRMSE($actual, $predictions),
                    'r2' => $this->evaluator->calculateR2($actual, $predictions),
                ];
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $results[$name] = ['error' => $exception->getMessage()];
            }
        }
        $eligible = array_filter($results, static fn ($result) => !isset($result['error']));
        if (!$eligible) {
            return ['sufficient' => false, 'models' => $results, 'recommended' => null, 'message' => 'No model could be fitted reliably to this history.'];
        }
        uasort($eligible, static fn ($a, $b) => $a['mae'] <=> $b['mae']);
        return ['sufficient' => true, 'models' => $results, 'recommended' => array_key_first($eligible), 'message' => 'Models are ranked by chronological holdout MAE; R² is supporting information.'];
    }

    public function generateForecast(array $history, string $modelName, int $days): array
    {
        if (count($history) < 8 || $days < 1 || $days > 365) {
            throw new \InvalidArgumentException('Choose a forecast period from 1 to 365 days and provide at least 8 observed sale dates.');
        }
        $dates = array_column($history, 'sale_date');
        $values = array_map('floatval', array_column($history, 'quantity'));
        $prices = array_map('floatval', array_column($history, 'average_price'));
        $origin = new \DateTimeImmutable($dates[0]);
        $x = array_map(static fn ($date) => (float) $origin->diff(new \DateTimeImmutable($date))->days, $dates);
        $factories = [
            'Linear' => static fn () => new LinearRegression(),
            'Polynomial (degree 2)' => static fn () => new PolynomialRegression(2),
            'Multiple linear' => static fn () => new MultipleRegression(),
        ];
        if (!isset($factories[$modelName])) {
            throw new \InvalidArgumentException('Select a supported regression model.');
        }
        $model = $factories[$modelName]();
        $trainingX = $modelName === 'Multiple linear'
            ? array_map(static fn ($index) => [$x[$index], $prices[$index]], array_keys($x))
            : $x;
        $model->calculate($trainingX, $values);

        $forecast = [];
        $lastDate = new \DateTimeImmutable(end($dates));
        $today = new \DateTimeImmutable('today');
        $forecastStart = $lastDate > $today ? $lastDate : $today;
        $averagePrice = array_sum($prices) / count($prices);
        for ($offset = 1; $offset <= $days; $offset++) {
            $forecastDate = $forecastStart->modify("+{$offset} days");
            $forecastDay = (float) $origin->diff($forecastDate)->days;
            $features = $modelName === 'Multiple linear' ? [$forecastDay, $averagePrice] : $forecastDay;
            $forecast[] = [
                'date' => $forecastDate->format('Y-m-d'),
                'quantity' => max(0.0, $model->predict($features)),
            ];
        }

        return ['equation' => $model->equation(), 'forecast' => $forecast];
    }
}
