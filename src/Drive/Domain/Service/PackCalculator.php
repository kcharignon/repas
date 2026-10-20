<?php

namespace Repas\Drive\Domain\Service;


use Repas\Drive\Domain\Model\LineQuantity;
use Repas\Drive\Domain\Model\PackResult;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Shared\Domain\Model\Dimension;

/**
 * Nombre de produits à mettre au panier pour une ligne de liste : « 300 g de farine » et « farine 1 kg » donnent 1, « 1,2 kg »
 * et « 500 g » donnent 3. Fonction pure, sans état (docs/drive/architecture.md § 6).
 */
final class PackCalculator
{
    /** Plafond de produits par ligne : au-delà, c'est probablement une erreur, l'utilisateur vérifie. */
    public const int MAX_PACKS = 99;

    /** Un quotient à moins de cette valeur d'un entier est cet entier (0,1 + 0,2 kg contre 300 g donne 1, pas 2). */
    public const float TOLERANCE = 1e-9;

    /**
     * @param ProductContent|null $content  le contenu net du produit (fait public de sa fiche)
     * @param PackSize|null       $packSize la contenance que la personne a donnée pour ce produit, dans l'unité de la liste
     *                                      (DRV-652) : elle passe avant le contenu net, mais seulement si son unité est EXACTEMENT
     *                                      celle de la ligne (la liste a pu changer d'unité depuis)
     */
    public function packsFor(LineQuantity $line, ?ProductContent $content, ?PackSize $packSize = null): PackResult
    {
        $quantity = $line->quantity;
        if (!is_finite($quantity) || $quantity <= 0) {
            return PackResult::toCheck(null);
        }

        // La liste compte déjà des produits (paquets, boîtes…) : ni la contenance ni le contenu n'entrent dans le calcul
        if ($line->dimension === Dimension::PACKAGE) {
            return $this->round($quantity, true);
        }

        $sizeInTheUnitOfTheLine = $this->usableSizeFor($line, $packSize);
        if ($sizeInTheUnitOfTheLine !== null) {
            return $this->round($quantity / $sizeInTheUnitOfTheLine->value, true);
        }

        return match ($line->dimension) {
            Dimension::COUNT => $this->forCount($quantity, $this->usable($content)),
            Dimension::WEIGHT, Dimension::VOLUME => $this->forMeasure($line, $this->usable($content)),
            null => PackResult::toCheck(null),
        };
    }

    /**
     * La contenance, si elle est exploitable (valeur finie et positive) et donnée dans l'unité exacte de la ligne.
     */
    private function usableSizeFor(LineQuantity $line, ?PackSize $packSize): ?PackSize
    {
        if ($packSize === null || $line->unitSlug === null || $packSize->unitSlug !== $line->unitSlug) {
            return null;
        }

        return is_finite($packSize->value) && $packSize->value > 0 ? $packSize : null;
    }

    private function forCount(float $quantity, ?ProductContent $content): PackResult
    {
        if ($content !== null && $content->unit->dimension() === Dimension::COUNT) {
            return $this->round($quantity / $content->value, true);
        }

        // Contenu inconnu ou qui n'est pas en pièces : une unité = un produit, à vérifier
        return $this->round($quantity, false);
    }

    private function forMeasure(LineQuantity $line, ?ProductContent $content): PackResult
    {
        $baseFactor = $line->baseFactor;
        if (
            $content === null
            || $content->unit->dimension() !== $line->dimension
            || $baseFactor === null
            || !is_finite($baseFactor)
            || $baseFactor <= 0
        ) {
            return PackResult::toCheck(null);
        }

        return $this->round($line->quantity * $baseFactor / ($content->value * $content->unit->factor()), true);
    }

    /**
     * Le contenu, s'il est exploitable (valeur finie et positive), sinon `null` comme un contenu inconnu.
     */
    private function usable(?ProductContent $content): ?ProductContent
    {
        return $content !== null && is_finite($content->value) && $content->value > 0 ? $content : null;
    }

    /**
     * Arrondi supérieur avec tolérance, minimum 1, plafond 99 (au-delà : 99 et à vérifier).
     */
    private function round(float $quotient, bool $reliable): PackResult
    {
        if (is_nan($quotient)) {
            return PackResult::toCheck(null);
        }

        $nearest = round($quotient);
        $packs = abs($quotient - $nearest) < self::TOLERANCE ? $nearest : ceil($quotient);

        if ($packs > self::MAX_PACKS) {
            return PackResult::toCheck(self::MAX_PACKS);
        }

        $packs = max(1, (int) $packs);

        return $reliable ? PackResult::computed($packs) : PackResult::toCheck($packs);
    }
}
