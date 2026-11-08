<?php

namespace Repas\Repas\Application\Command\RemoveRecipe;

use Repas\Shared\Domain\Event\RecipeRemovedEvent;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\MarmitonImportLock;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Supprime une recette et ses lignes. Une recette SANS PROPRIÉTAIRE (la neutre d'une recette Marmiton, ADR 0037, amendement du 2026-11-08) ne
 * se supprime que par un administrateur (`byAdministrator`) : c'est le moyen de repartir d'un import raté, le tag Marmiton est alors libéré et
 * un nouvel import de la même page recrée une neutre neuve. Les copies déjà faites restent à leurs auteurs, telles quelles (avec leur lien
 * d'origine) ; une neutre encore planifiée dans une liste ne se supprime pas (`409`), comme toute recette. Pour elle :
 *
 * - le verrou de l'identifiant Marmiton est pris, le MÊME que celui d'un import, pour qu'un import concurrent ne copie pas une neutre en train
 *   de disparaître : il attend la fin de la suppression, puis recrée la neutre ;
 * - aucune statistique ni événement, aucune phrase vocale à effacer : le propriétaire réservé n'est pas un compte.
 */
#[AsMessageHandler]
readonly class RemoveRecipeHandler
{

    public function __construct(
        private RecipeRepository $recipeRepository,
        private ShoppingListRepository $shoppingListRepository,
        private EventDispatcherInterface $eventDispatcher,
        private SpokenRecipePhraseChoiceRepository $spokenRecipePhraseRepository,
        private MarmitonImportLock $marmitonImportLock,
    ) {
    }

    /**
     * @throws RecipeException
     */
    public function __invoke(RemoveRecipeCommand $command): void
    {
        $recipe = $this->recipeRepository->findOneById($command->recipeId);
        if ($command->byAdministrator) {
            // Seul un administrateur supprime une recette sans propriétaire ; sans effet sur une recette ordinaire (son droit est `RECIPE_OWNER`)
            $recipe->allowAdministratorEdition();
        }
        // Sans cela, la recette sans propriétaire est figée pour tous : le droit est refusé en amont, la garde le répète
        $recipe->assertNotFrozen();
        $recipe->assertVersion($command->expectedVersion);

        if ($recipe->isMarmitonNeutral() && $recipe->getMarmitonId() !== null) {
            $this->marmitonImportLock->acquire($recipe->getMarmitonId());
        }

        if ($this->shoppingListRepository->isRecipePlanned($recipe->getId())) {
            throw RecipeException::cannotRemoveExistInShoppingList($recipe->getId());
        }

        $this->recipeRepository->delete($recipe);
        if ($recipe->isMarmitonNeutral()) {
            // Le propriétaire réservé n'est pas un compte : ni statistiques à défaire ni phrases apprises à Alexa
            return;
        }
        // Et les phrases que l'utilisateur avait apprises à Alexa pour cette recette (ADR 0029 décision 13)
        $this->spokenRecipePhraseRepository->deleteByRecipeId($recipe->getAuthorId(), $recipe->getId());
        $this->eventDispatcher->dispatch(new RecipeRemovedEvent($recipe->getAuthorId(), $recipe->getId()));
    }
}
