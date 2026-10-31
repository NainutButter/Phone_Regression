<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Regression/RegressionModel.php';
require_once __DIR__ . '/../app/Regression/Regression.php';
require_once __DIR__ . '/../app/Regression/LinearRegression.php';
require_once __DIR__ . '/../app/Regression/PolynomialRegression.php';
require_once __DIR__ . '/../app/Regression/MultipleRegression.php';
require_once __DIR__ . '/../app/Regression/ModelEvaluator.php';
require_once __DIR__ . '/../app/Services/PurchaseAdvisor.php';

use App\Regression\LinearRegression;
use App\Regression\ModelEvaluator;
use App\Regression\MultipleRegression;
use App\Regression\PolynomialRegression;
use App\Services\PurchaseAdvisor;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function near(float $actual, float $expected, float $epsilon = 1e-8): bool
{
    return abs($actual - $expected) <= $epsilon;
}

$linear = new LinearRegression();
$linear->calculate([1, 2, 3, 4], [3, 5, 7, 9]);
expect(near($linear->predict(5), 11), 'Linear prediction failed.');

$polynomial = new PolynomialRegression(2);
$polynomial->calculate([-2, -1, 0, 1, 2], [3, 0, 1, 6, 15]);
expect(near($polynomial->predict(3), 28), 'Polynomial prediction failed.');

$multiple = new MultipleRegression();
$multiple->calculate([[1, 0], [2, 1], [3, 0], [4, 2], [5, 1]], [5, 10, 9, 17, 16]);
expect(near($multiple->predict([6, 2]), 21), 'Multiple regression prediction failed.');

$evaluator = new ModelEvaluator();
expect(near($evaluator->calculateMAE([1, 3], [2, 2]), 1), 'MAE calculation failed.');
expect(near($evaluator->calculateMSE([1, 3], [2, 2]), 1), 'MSE calculation failed.');
expect(near($evaluator->calculateRMSE([1, 3], [2, 2]), 1), 'RMSE calculation failed.');
expect(near($evaluator->calculateR2([1, 3], [1, 3]), 1), 'R-squared calculation failed.');

$advisor = new PurchaseAdvisor();
expect($advisor->calculateRecommendedQuantity(100, 15, 30, 10) === 75, 'Purchase quantity example failed.');
expect($advisor->calculateRecommendedQuantity(10, 2, 40, 0) === 0, 'Purchase quantity floor failed.');

echo "Regression and purchase recommendation checks passed.\n";
