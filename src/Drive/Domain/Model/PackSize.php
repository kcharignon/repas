<?php

namespace Repas\Drive\Domain\Model;


use Repas\Drive\Domain\Exception\DriveException;

/**
 * Ce que contient un produit, compté dans l'unité de la liste de courses (DRV-652) : « 20 tranches » pour le cheddar dont la liste
 * dit « 15 tranches ». Contrairement au contenu net (`ProductContent`, en g, ml ou pièces, fait public de la fiche du produit), la
 * contenance dit combien d'une unité SANS dimension (tranche, cuillère…) tient dans un produit, ce que `PackCalculator` ne peut pas
 * déduire du contenu net. C'est une réponse de la personne, privée : elle vit sur la ligne du produit dans sa préférence
 * (`Choice`), jamais sur la fiche commune du produit, et s'efface avec elle.
 *
 * Elle ne vaut que pour l'unité exacte qui l'a fait naître : si la liste change d'unité, le calculateur l'ignore. Rien ici ne
 * dépend d'une enseigne.
 *
 * Valeur : de 0,001 à 9 999, trois décimales au plus (la précision stockée) ; unité : le slug d'une unité de Repas (l'existence
 * de l'unité se contrôle à l'écriture, par un port de `Shared`, pas ici).
 */
final readonly class PackSize
{
    public const float MIN_VALUE = 0.001;
    public const float MAX_VALUE = 9999.0;
    public const int DECIMALS = 3;
    public const string UNIT_SLUG_PATTERN = '/^[a-z0-9-]{1,255}$/D';

    /** Un nombre à moins de cette distance de sa valeur arrondie à trois décimales n'a pas de quatrième décimale. */
    private const float TOLERANCE = 1e-9;

    private function __construct(
        public float $value,
        public string $unitSlug,
    ) {
    }

    /**
     * @throws DriveException `packSizeInvalid()` (422) pour une valeur qui n'est pas un nombre fini de 0,001 à 9 999 avec trois
     *                        décimales au plus, ou un slug d'unité mal formé
     */
    public static function of(float $value, string $unitSlug): self
    {
        $valid = is_finite($value)
            && $value >= self::MIN_VALUE
            && $value <= self::MAX_VALUE
            && abs($value - round($value, self::DECIMALS)) < self::TOLERANCE
            && preg_match(self::UNIT_SLUG_PATTERN, $unitSlug) === 1;
        if (!$valid) {
            throw DriveException::packSizeInvalid();
        }

        return new self($value, $unitSlug);
    }

    /**
     * Reconstruction depuis la persistance : aucun contrôle (la valeur a été contrôlée par `of()` à l'écriture).
     */
    public static function load(float $value, string $unitSlug): self
    {
        return new self($value, $unitSlug);
    }
}
