<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

final class Unit implements ModelInterface
{
    use ModelTrait;

    /**
     * @param ?Dimension $dimension  ce que l'unité mesure ; null pour une unité sans dimension connue (cuillère, tranche…)
     * @param ?float     $baseFactor combien d'unités de base (g, ml, pièce) vaut une unité ; renseigné si et seulement si la dimension l'est
     */
    private function __construct(
        private string $slug,
        private string $name,
        private string $symbol,
        private ?Dimension $dimension,
        private ?float $baseFactor,
    ) {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getId(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getDimension(): ?Dimension
    {
        return $this->dimension;
    }

    public function getBaseFactor(): ?float
    {
        return $this->baseFactor;
    }

    /**
     * @throws UnitException la dimension et le facteur de base ne vont pas ensemble, ou le facteur n'est pas un nombre fini de 0,000001 à 1 000 000
     */
    public static function create(
        string $name,
        string $symbol,
        ?Dimension $dimension = null,
        ?float $baseFactor = null,
    ): self {
        self::assertMeasure($dimension, $baseFactor);

        $slug = StringTool::slugify($name);
        return new self(
            $slug,
            $name,
            $symbol,
            $dimension,
            $baseFactor,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            $datas['slug'],
            $datas['name'],
            $datas['symbol'],
            $datas['dimension'] ?? null,
            $datas['baseFactor'] ?? null,
        );
    }

    public function update(string $name, string $symbol): void
    {
        $this->name = $name;
        $this->symbol = $symbol;
    }

    /**
     * Remplace la dimension et le facteur de base ensemble (`null`, `null` les efface).
     *
     * @throws UnitException la dimension et le facteur de base ne vont pas ensemble, ou le facteur n'est pas un nombre fini de 0,000001 à 1 000 000
     */
    public function setMeasure(?Dimension $dimension, ?float $baseFactor): static
    {
        self::assertMeasure($dimension, $baseFactor);

        $this->dimension = $dimension;
        $this->baseFactor = $baseFactor;

        return $this;
    }

    /**
     * @throws UnitException
     */
    private static function assertMeasure(?Dimension $dimension, ?float $baseFactor): void
    {
        if (($dimension === null) !== ($baseFactor === null)) {
            throw UnitException::incompleteMeasure();
        }

        if ($baseFactor !== null && !QuantityBounds::isCoefficient($baseFactor)) {
            throw UnitException::invalidBaseFactor();
        }
    }
}
