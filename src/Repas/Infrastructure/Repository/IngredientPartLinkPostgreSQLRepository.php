<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Interface\IngredientPartLinkRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Adoption\PartLinkSql;

/**
 * La liaison des sous-ingrédients d'un compte (ADR 0038 décision 21) en UNE instruction SQL, quel que soit le nombre d'ingrédients : la règle est
 * celle de `PartLinkSql`, que le rapport de l'amorçage lit aussi. Aucun agrégat n'est hydraté et aucun événement n'est émis ; l'`UPDATE` ne
 * touche qu'aux ingrédients du compte, et seulement à ceux qui n'ont toujours pas de parent au moment de l'écriture (un lien posé entre-temps
 * par une écriture simultanée n'est jamais écrasé).
 *
 * Les entités Doctrine déjà chargées dans la requête (les copies qu'une copie de recette vient d'enregistrer) ne voient pas ces colonnes changer :
 * l'appelant n'enregistre plus ces ingrédients ensuite.
 */
readonly class IngredientPartLinkPostgreSQLRepository implements IngredientPartLinkRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function linkCopies(string $userId): int
    {
        // Le propriétaire réservé des recettes Marmiton n'est pas un compte : ses ingrédients n'ont jamais de parent (ADR 0037)
        if ($userId === Recipe::MARMITON_OWNER_ID) {
            return 0;
        }

        return (int) $this->connection()->executeStatement(
            'WITH links AS (' . PartLinkSql::ofOneAccount() . ')'
            . ' UPDATE ingredient i SET parent_slug = links.parent, parent_ratio = links.ratio'
            . ' FROM links WHERE i.slug = links.child AND i.creator = :u AND i.parent_slug IS NULL',
            ['u' => $userId, 'max' => Ingredient::MAX_PARTS],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
