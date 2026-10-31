<?php

namespace Repas\Drive\Application\OpenHostService;

use Repas\Drive\Domain\Interface\IngredientChoiceRepository;
use Repas\Drive\Domain\Model\IngredientChoiceFingerprint;
use Repas\Drive\Domain\Model\IngredientChoiceMergeImpact;

/**
 * Open Host Service d'écriture du contexte `Drive` (ADR 0035) : les choix de produits d'un utilisateur vus par ingrédient, pour un autre
 * contexte qui écrit et dont les ingrédients changent de slug (amorçage d'un compte) ou fusionnent. Le contexte `Drive` reste maître de ses
 * tables, de sa clé unique (utilisateur, enseigne, magasin, ingrédient) et de ses produits mémorisés : l'appelant ne lit aucune table du drive.
 */
final readonly class IngredientChoiceCommandOHS
{
    public function __construct(
        private IngredientChoiceRepository $choices,
    ) {
    }

    /**
     * L'empreinte de ce que le drive mémorise pour l'utilisateur : ses choix et leurs produits, vus sans le slug d'ingrédient.
     */
    public function fingerprint(string $userId): IngredientChoiceFingerprint
    {
        return $this->choices->fingerprint($userId);
    }

    /**
     * Les slugs des ingrédients que les choix de l'utilisateur désignent (toutes enseignes, tous magasins), sans doublon, triés.
     *
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array
    {
        return $this->choices->referencedSlugs($userId);
    }

    /**
     * Fait passer les choix de l'utilisateur d'un slug d'ingrédient à un autre, pour toutes ses enseignes et tous ses magasins : seul le slug
     * change. Un choix dont le slug cible est déjà pris dans le même magasin n'est pas touché.
     *
     * @param array<string, string> $slugMap nouveau slug par ancien slug
     *
     * @return int le nombre de choix repointés
     */
    public function repoint(string $userId, array $slugMap): int
    {
        return $this->choices->repoint($userId, $slugMap);
    }

    /**
     * La fusion de l'ingrédient absorbé dans l'ingrédient gardé : le choix du gardé gagne là où il existe, celui de l'absorbé passe sur le
     * gardé, avec ses produits, ailleurs.
     */
    public function merge(string $userId, string $absorbedSlug, string $keptSlug): IngredientChoiceMergeImpact
    {
        return $this->choices->merge($userId, $absorbedSlug, $keptSlug);
    }
}
