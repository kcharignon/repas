<?php

namespace Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser;


/**
 * Retour arrière des DONNÉES d'un lancement de la migration d'adoption, pour UN utilisateur (`docs/ingredients/migration.md` § 10) :
 * repointe vers le commun d'origine ce qui référence encore les copies du lancement et efface les alias de ce lancement. Les copies et
 * leurs conversions restent en place (jamais de suppression de donnée de production par ce chemin). Décision humaine, jamais
 * automatique ; lancée par `repas:ingredients:adopt-commons --undo=<lancement> --apply`.
 */
final readonly class UndoCommonIngredientsAdoptionForUserCommand
{
    /**
     * @param string $source le nom du lancement à défaire (`ingredient_alias.source`)
     * @param bool $acceptSent autorise le repointage des listes déjà envoyées au drive
     */
    public function __construct(
        public string $userId,
        public string $source,
        public bool $acceptSent = false,
    ) {
    }
}
