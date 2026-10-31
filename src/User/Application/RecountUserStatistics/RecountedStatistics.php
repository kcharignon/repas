<?php

namespace Repas\User\Application\RecountUserStatistics;

/** Les compteurs écrits pour le compte. */
final readonly class RecountedStatistics
{
    public function __construct(
        public int $ingredients,
        public int $recipes,
    ) {
    }
}
