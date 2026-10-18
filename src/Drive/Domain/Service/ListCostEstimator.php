<?php

namespace Repas\Drive\Domain\Service;

use DateTimeImmutable;
use Repas\Drive\Domain\Model\CostEstimate;
use Repas\Drive\Domain\Model\EstimatedLine;
use Repas\Drive\Domain\Model\EstimateLineInput;
use Repas\Drive\Domain\Model\EstimateLineStatus;
use Repas\Drive\Domain\Model\PackStatus;

/**
 * Estime le coût d'une liste d'après le produit préféré et le dernier prix vu de chaque ligne (décision D22). Une ligne
 * n'entre dans le total que si tout est sûr : produit, prix daté, nombre de produits calculé par `PackCalculator`. Sinon elle
 * est listée comme non estimée, jamais devinée. Pur : aucune lecture, aucune horloge.
 */
final readonly class ListCostEstimator
{
    private const int COVERAGE_PRECISION = 4;

    public function __construct(
        private PackCalculator $packCalculator,
    ) {
    }

    /**
     * @param list<EstimateLineInput> $inputs
     */
    public function estimate(array $inputs): CostEstimate
    {
        $lines = array_map($this->line(...), $inputs);

        $estimated = array_values(array_filter($lines, static fn(EstimatedLine $line): bool => $line->status === EstimateLineStatus::ESTIMATED));
        $toBuy = count(array_filter($lines, static fn(EstimatedLine $line): bool => $line->status !== EstimateLineStatus::HAVE_AT_HOME));
        $dates = array_values(array_filter(
            array_map(static fn(EstimatedLine $line): ?DateTimeImmutable => $line->priceSeenAt, $estimated),
            static fn(?DateTimeImmutable $date): bool => $date !== null,
        ));

        return new CostEstimate(
            totalCents: array_sum(array_map(static fn(EstimatedLine $line): int => $line->subtotalCents ?? 0, $estimated)),
            estimatedLines: count($estimated),
            unestimatedLines: $toBuy - count($estimated),
            coverage: $toBuy === 0 ? 0.0 : round(count($estimated) / $toBuy, self::COVERAGE_PRECISION),
            oldestPriceAt: $dates === [] ? null : min($dates),
            newestPriceAt: $dates === [] ? null : max($dates),
            lines: $lines,
        );
    }

    private function line(EstimateLineInput $input): EstimatedLine
    {
        $product = $input->product;
        if ($input->haveAtHome) {
            return $this->unestimated($input, EstimateLineStatus::HAVE_AT_HOME);
        }
        if ($product === null) {
            return $this->unestimated($input, EstimateLineStatus::NO_PRODUCT);
        }

        $price = $product->price;
        if ($price === null || $product->priceSeenAt === null) {
            return $this->unestimated($input, EstimateLineStatus::NO_PRICE, $product->label);
        }

        $result = $this->packCalculator->packsFor($input->quantity, $product->content);
        $packs = $result->status === PackStatus::COMPUTED ? $result->packs : null;

        return new EstimatedLine(
            ingredientSlug: $input->ingredientSlug,
            ingredientName: $input->ingredientName,
            status: $packs === null ? EstimateLineStatus::TO_CHECK : EstimateLineStatus::ESTIMATED,
            productLabel: $product->label,
            packs: $packs,
            unitPriceCents: $price->cents,
            subtotalCents: $packs === null ? null : $packs * $price->cents,
            priceSeenAt: $product->priceSeenAt,
        );
    }

    private function unestimated(EstimateLineInput $input, EstimateLineStatus $status, ?string $productLabel = null): EstimatedLine
    {
        return new EstimatedLine($input->ingredientSlug, $input->ingredientName, $status, $productLabel, null, null, null, null);
    }
}
