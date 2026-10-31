<?php

namespace Repas\Drive\Application\Command\MarkLinesInBasket;

use Repas\Drive\Application\Port\BasketLineCommandPort;
use Repas\Drive\Application\Port\IngredientAliasCommandPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class MarkLinesInBasketHandler
{
    public function __construct(
        private IngredientAliasCommandPort $ingredientAliases,
        private BasketLineCommandPort $basketLines,
    ) {
    }

    /**
     * Coche les lignes de la liste envoyée dont l'agent a mis l'article au panier (DRV-745, ADR 0023 décision 3 amendée). Ne pose aucun choix et
     * ne lit aucun réglage : l'interrupteur et le plafond sont ceux du controller, le propriétaire, le statut `SENT` et la coche sont ceux de la
     * liste (Open Host Service de `Repas`). Idempotent ; un slug qui n'est pas dans la liste est ignoré.
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException liste inconnue (404), à un autre utilisateur (403), pas envoyée au drive (409)
     */
    public function __invoke(MarkLinesInBasketCommand $command): void
    {
        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) désigne son ingrédient actuel (ADR 0028 décision 4) ;
        // un slug sans alias reste tel quel, et il est simplement absent de la liste s'il n'y est pas
        $translated = $this->ingredientAliases->resolve($command->userId, $command->ingredientSlugs);
        $slugs = array_values(array_unique(array_map(
            static fn(string $slug): string => $translated[$slug] ?? $slug,
            $command->ingredientSlugs,
        )));

        $this->basketLines->check($command->userId, $command->shoppingListId, $slugs);
    }
}
