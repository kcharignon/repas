<?php

namespace Repas\Repas\Application\Command\AdoptCommonIngredientsForUser;


use Repas\Repas\Domain\Model\AdoptionRepointed;

/**
 * Ce que la migration d'adoption a fait pour un utilisateur : des nombres seulement (la sortie de la commande est conservée dans des
 * journaux, aucune donnée personnelle).
 */
final readonly class AdoptionResult
{
    public function __construct(
        public bool $concerned,
        /** Communs adoptés par ce passage avec une copie CRÉÉE. */
        public int $adopted = 0,
        /** Communs adoptés par ce passage en désignant un ingrédient de l'utilisateur déjà né d'eux et strictement conforme (aucune copie créée). */
        public int $reused = 0,
        /** Dont le slug a dû prendre un suffixe (`-2`…) parce qu'un ingrédient de l'utilisateur portait déjà le slug naturel. */
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

    public static function notConcerned(): self
    {
        return new self(false);
    }

    /**
     * Rien n'a été écrit : aucun commun à adopter, aucune ligne à repointer.
     */
    public function isNoop(): bool
    {
        return $this->adopted === 0 && $this->reused === 0 && $this->repointed->isEmpty() && $this->driveChoicesRepointed === 0;
    }
}
