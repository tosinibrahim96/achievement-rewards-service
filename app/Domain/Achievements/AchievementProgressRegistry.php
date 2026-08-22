<?php

declare(strict_types=1);

namespace App\Domain\Achievements;

use App\Contracts\Achievements\AchievementProgressCalculator;
use App\Enums\AchievementMetric;
use Illuminate\Container\Attributes\Tag;
use LogicException;

final readonly class AchievementProgressRegistry
{
    /** @var array<string, AchievementProgressCalculator> */
    private array $calculators;

    /** @param iterable<int, AchievementProgressCalculator> $calculators */
    public function __construct(
        #[Tag(AchievementProgressCalculator::class)] iterable $calculators,
    ) {
        $indexed = [];

        foreach ($calculators as $calculator) {
            $metric = $calculator->metric()->value;

            if (array_key_exists($metric, $indexed)) {
                throw new LogicException("Multiple achievement progress calculators are registered for [{$metric}].");
            }

            $indexed[$metric] = $calculator;
        }

        foreach (AchievementMetric::cases() as $metric) {
            if (! array_key_exists($metric->value, $indexed)) {
                throw new LogicException("No achievement progress calculator is registered for [{$metric->value}].");
            }
        }

        $this->calculators = $indexed;
    }

    public function for(AchievementMetric $metric): AchievementProgressCalculator
    {
        return $this->calculators[$metric->value];
    }
}
