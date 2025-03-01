<?php

namespace Repas\User\Application\UpdateUserStatistics;


readonly class UpdateUserStatisticsCommand
{

    public function __construct(
        public string $userId,
        public int    $ingredients,
        public int    $recipes,
    ) {
    }
}
