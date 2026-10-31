<?php

namespace Repas\Repas\Application\Command\UpdateUnit;


use Repas\Repas\Domain\Model\Dimension;

readonly class UpdateUnitCommand
{
    /**
     * @param bool $replaceMeasure faux : la dimension et le facteur de base de l'unité ne sont pas touchés ; vrai : la paire
     *                             `$dimension` / `$baseFactor` les remplace (`null`, `null` les efface)
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $symbol,
        public bool $replaceMeasure = false,
        public ?Dimension $dimension = null,
        public ?float $baseFactor = null,
    ) {
    }
}
