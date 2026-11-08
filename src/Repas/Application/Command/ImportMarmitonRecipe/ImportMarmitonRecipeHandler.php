<?php

namespace Repas\Repas\Application\Command\ImportMarmitonRecipe;


use Repas\Repas\Application\Service\MarmitonNeutralRecipeCreator;
use Repas\Repas\Application\Service\RecipeCopier;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\MarmitonImportLimiter;
use Repas\Repas\Domain\Interface\MarmitonImportLock;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\MarmitonUrl;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importe une recette Marmiton chez un utilisateur (ADR 0037 décision 9, `docs/recipes/tickets/MAR-003`). Dans la transaction du bus :
 *
 * 1. l'adresse est analysée STRICTEMENT (`MarmitonUrl::parse`) : on n'en garde que l'identifiant, l'adresse lue est reconstruite ;
 * 2. le VERROU de cet identifiant est pris (deux imports du même lien se suivent) : tout ce qui suit est relu sous le verrou ;
 * 3. si l'utilisateur a DÉJÀ une copie de la neutre, on la lui rend, rien n'est écrit ni lu sur Marmiton (`created` faux) ;
 * 4. le plafond de recettes du compte est jugé AVANT tout appel sortant (`RecipeCopier::assertCanCopy`) ;
 * 5. si la neutre n'existe pas, le plafond d'imports est consommé (`MarmitonImportLimiter`), la page est lue UNE fois et la neutre créée
 *    (`MarmitonNeutralRecipeCreator`) ; si elle existe, Marmiton n'est PAS appelé ;
 * 6. l'utilisateur reçoit sa copie, par le même service que `POST /recipes/{id}/copy` (`RecipeCopier`).
 *
 * Une page illisible, introuvable ou indisponible, ou un plafond atteint, annule tout (rien n'est écrit : le verrou est relâché au rollback).
 * La neutre ne déclenche aucun événement ni statistique (elle n'est à aucun compte) ; la copie annonce la sienne au chemin normal.
 */
#[AsMessageHandler]
readonly class ImportMarmitonRecipeHandler
{
    public function __construct(
        private RecipeRepository $recipeRepository,
        private MarmitonImportLock $lock,
        private MarmitonImportLimiter $limiter,
        private MarmitonPageReader $pageReader,
        private MarmitonNeutralRecipeCreator $neutralCreator,
        private RecipeCopier $copier,
    ) {
    }

    /**
     * @throws MarmitonImportException l'adresse n'est pas celle d'une recette Marmiton, la recette est introuvable ou illisible, ou Marmiton ne répond pas
     * @throws RateLimitedException le plafond d'imports de l'utilisateur est atteint (aucune page lue)
     * @throws RecipeException l'utilisateur a déjà `Recipe::MAX_PER_AUTHOR_WITH_COPIES` recettes
     */
    public function __invoke(ImportMarmitonRecipeCommand $command): ImportedMarmitonRecipe
    {
        $url = MarmitonUrl::parse($command->url);

        // Sous le verrou : un import simultané du même lien est vu en entier ou pas du tout
        $this->lock->acquire($url->id);

        $neutralId = $this->recipeRepository->findMarmitonNeutralId($url->id);
        if ($neutralId !== null) {
            $existingCopyId = $this->recipeRepository->findCopyIdOf($neutralId, $command->userId);
            if ($existingCopyId !== null) {
                return new ImportedMarmitonRecipe($existingCopyId, false);
            }
        }

        // Le plafond du compte avant tout appel sortant : un compte qui l'a atteint ne coûte rien de plus
        $this->copier->assertCanCopy($command->userId);

        if ($neutralId === null) {
            $this->limiter->consume($command->userId);
            $neutral = $this->neutralCreator->create($url, $this->pageReader->read($url));
        } else {
            $neutral = $this->recipeRepository->findOneById($neutralId);
        }

        $this->copier->copy($neutral, $command->userId, $command->newRecipeId);

        return new ImportedMarmitonRecipe($command->newRecipeId, true);
    }
}
