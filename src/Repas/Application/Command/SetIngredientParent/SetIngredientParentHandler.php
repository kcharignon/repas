<?php

namespace Repas\Repas\Application\Command\SetIngredientParent;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Découpe un ingrédient de l'utilisateur : il devient une partie d'un autre (« Jaune d'œuf » est une partie d'« Œuf », ratio 1), ou change de
 * parent, ou de ratio (ADR 0038 décisions 2, 3 et 28). Idempotent : refaire le même appel n'écrit rien.
 *
 * Les contrôles se font dans cet ordre, TOUS avant la première écriture (rien n'est écrit sur un refus) :
 *  1. les deux slugs sont traduits par les alias de l'utilisateur ;
 *  2. les deux ingrédients sont à l'utilisateur, sans passe-droit d'administrateur : le parent d'un autre est inconnu (`404`) ;
 *  3. un ingrédient n'est pas sa propre partie (`422 INGREDIENT_PART_OF_ITSELF`) ;
 *  4. profondeur 1 : le parent n'est pas une partie et la partie n'a pas de partie (`422 INGREDIENT_PART_CHAIN`) ;
 *  5. seulement quand le PARENT CHANGE (changer le ratio ou refaire le même appel n'est jamais refusé) : l'ingrédient n'a pas de ligne dans une
 *     liste non terminée (`409 INGREDIENT_PART_IN_LIST`, une requête), puis le parent a moins de `Ingredient::MAX_PARTS` parties
 *     (`422 INGREDIENT_PART_LIMIT_REACHED`, un test d'existence) ;
 *  6. le ratio est un nombre fini de 0,000001 à 1 000 000 (`422 INGREDIENT_PART_RATIO_INVALID`, jugé par le modèle).
 *
 * Aucune conversion ne change et les unités compatibles non plus : `ConversionService::clear()` n'est pas nécessaire. Les listes en cours
 * ne sont jamais recalculées.
 */
#[AsMessageHandler]
readonly class SetIngredientParentHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private ShoppingListRepository $shoppingListRepository,
        private IngredientSlugResolver $slugResolver,
    ) {
    }

    /**
     * @throws IngredientException
     */
    public function __invoke(SetIngredientParentCommand $command): void
    {
        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) désigne l'ingrédient actuel (ADR 0028 décision 4)
        $resolved = $this->slugResolver->resolve($command->userId, [$command->ingredientSlug, $command->parentSlug]);
        $ingredient = $this->ingredientRepository->findOneBySlug($resolved[$command->ingredientSlug] ?? $command->ingredientSlug);
        $ingredient->assertOwnedBy($command->userId);
        $parent = $this->ingredientRepository->findOneBySlug($resolved[$command->parentSlug] ?? $command->parentSlug);
        $parent->assertOwnedBy($command->userId);

        if ($ingredient->getSlug() === $parent->getSlug()) {
            throw IngredientException::partOfItself();
        }
        if ($parent->isPart() || $this->ingredientRepository->hasParts($ingredient->getSlug())) {
            throw IngredientException::partChain();
        }

        // Ce qui change l'état des listes et du parent n'est jugé que si le parent change : le ratio seul se corrige toujours
        $parentChanges = $ingredient->getParentSlug() !== $parent->getSlug();
        if ($parentChanges) {
            if ($this->shoppingListRepository->isIngredientInOpenList($ingredient->getSlug())) {
                throw IngredientException::partInOpenList();
            }
            if ($this->ingredientRepository->partsHaveAtLeast($parent->getSlug(), Ingredient::MAX_PARTS)) {
                throw IngredientException::partLimitReached();
            }
        }

        // Le modèle juge le ratio ; un appel qui ne change rien n'écrit rien
        $unchanged = !$parentChanges && $ingredient->getParentRatio() === $command->ratio;
        $ingredient->makePartOf($parent->getSlug(), $command->ratio);
        if (!$unchanged) {
            $this->ingredientRepository->save($ingredient);
        }
    }
}
