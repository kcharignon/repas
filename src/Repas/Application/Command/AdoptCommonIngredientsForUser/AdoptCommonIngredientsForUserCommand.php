<?php

namespace Repas\Repas\Application\Command\AdoptCommonIngredientsForUser;


/**
 * Migration d'adoption des communs pour UN utilisateur (ADR 0028 décision 5, ING-003, `docs/ingredients/migration.md` § 7) : lui créer
 * une copie de chaque commun qu'il n'a pas adopté et repointer vers ces copies tout ce qui, chez lui, référençait un commun (lignes de ses
 * recettes, de ses listes, choix du drive). Une transaction, donc un utilisateur migré en entier ou pas du tout. Lancée par la commande de
 * console `repas:ingredients:adopt-commons`, jamais par une route.
 */
final readonly class AdoptCommonIngredientsForUserCommand
{
    /**
     * @param string $source le nom du lancement, écrit dans chaque alias (`migration-AAAA-MM-JJ`, 64 caractères au plus)
     * @param bool $acceptSent autorise le repointage des listes déjà envoyées au drive (immuables pour l'API, ADR 0023)
     */
    public function __construct(
        public string $userId,
        public string $source,
        public bool $acceptSent = false,
    ) {
    }
}
