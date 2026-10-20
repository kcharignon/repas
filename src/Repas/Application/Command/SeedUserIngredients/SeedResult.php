<?php

namespace Repas\Repas\Application\Command\SeedUserIngredients;


use Repas\Repas\Domain\Model\AdoptionRepointed;

/**
 * Ce que l'amorçage d'un compte a fait : des nombres seulement (la sortie de la commande de rattrapage est conservée dans des journaux,
 * aucune donnée personnelle).
 */
final readonly class SeedResult
{
    public function __construct(
        public SeedOutcome $outcome,
        /** Ingrédients copiés avec une copie CRÉÉE. */
        public int $seeded = 0,
        /** Dont la copie a été demandée parce que les lignes du compte référençaient un ingrédient qui n'est pas « de base ». */
        public int $seededBecauseReferenced = 0,
        /** Ingrédients désignés par l'alias sans copie créée : un ingrédient du compte déjà né de celui-là et strictement conforme. */
        public int $reused = 0,
        /** Copies dont le slug a dû prendre un suffixe (`-2`…) parce qu'un ingrédient du compte portait déjà le slug naturel. */
        public int $slugCollisions = 0,
        public int $conversionsCopied = 0,
        public AdoptionRepointed $repointed = new AdoptionRepointed(),
        public int $driveChoicesRepointed = 0,
        /** Listes déjà envoyées au drive qui ont été repointées. */
        public int $sentShoppingListsRepointed = 0,
        /** Somme de contrôle du contenu (comptages, sommes, contenu des recettes et des listes), identique avant et après. */
        public string $fingerprint = '',
    ) {
    }

    public static function of(SeedOutcome $outcome): self
    {
        return new self($outcome);
    }

    public function wroteSomething(): bool
    {
        return $this->outcome === SeedOutcome::SEEDED;
    }
}
