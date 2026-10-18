<?php

namespace Repas\Drive\Domain\Service;


use Repas\Drive\Domain\Model\LineQuantity;
use Repas\Drive\Domain\Model\PackResult;
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

    public function packsFor(LineQuantity $line, ?ProductContent $content): PackResult
    {
        $quantity = $line->quantity;
        if (!is_finite($quantity) || $quantity <= 0) {
            return PackResult::toCheck(null);
        }

        return match ($line->dimension) {
            // La liste compte déjà des produits (paquets, boîtes…) : le contenu n'entre pas dans le calcul
            Dimension::PACKAGE => $this->round($quantity, true),
            Dimension::COUNT => $this->forCount($quantity, $this->usable($content)),
            Dimension::WEIGHT, Dimension::VOLUME => $this->forMeasure($line, $this->usable($content)),
            null => PackResult::toCheck(null),
        };
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
