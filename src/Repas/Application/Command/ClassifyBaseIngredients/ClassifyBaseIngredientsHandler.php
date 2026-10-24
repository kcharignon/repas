<?php

namespace Repas\Repas\Application\Command\ClassifyBaseIngredients;


use Repas\Repas\Domain\Exception\IngredientCatalogException;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Repas\Repas\Domain\Interface\IngredientCatalogHandoverRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Phase 1 de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` § 6) : pose `ingredient.is_base` sur les anciens communs
 * (les ingrédients SANS créateur) d'après le fichier de classement validé, 151 « de base » et 110 « spécifiques ». Elle doit passer AVANT
 * l'amorçage (phase 2) : sans classement, l'amorçage n'a rien à copier et ne marque aucun compte.
 *
 * Elle REFUSE d'écrire si l'ensemble des ingrédients sans créateur n'est pas EXACTEMENT celui du fichier (un commun inconnu ou manquant : il
 * faut le classer d'abord, le fichier est une donnée validée qu'on ne « corrige » pas ici). Idempotente : un classement déjà posé n'écrit
 * aucune ligne. Il n'en reste aucun à classer une fois la passation faite : un marqueur que le propriétaire a changé depuis lui appartient, on
 * n'y touche pas. Aucun événement, aucune statistique ; elle ne crée ni ne supprime aucun ingrédient et n'en charge aucun.
 */
#[AsMessageHandler]
readonly class ClassifyBaseIngredientsHandler
{
    public function __construct(
        private IngredientCatalogHandoverRepository $repository,
        private BaseIngredientClassification $classification,
    ) {
    }

    /**
     * @throws IngredientCatalogException le classement ne correspond pas aux ingrédients sans créateur
     */
    public function __invoke(ClassifyBaseIngredientsCommand $command): ClassificationResult
    {
        $this->repository->lock();

        $classification = $this->classification->all();
        $withoutCreator = $this->repository->slugsWithoutCreator();
        if ($withoutCreator === []) {
            return new ClassificationResult(0, 0, 0, 0);
        }

        $unknown = count(array_diff($withoutCreator, array_keys($classification)));
        $missing = count(array_diff(array_keys($classification), $withoutCreator));
        if ($unknown > 0 || $missing > 0) {
            throw IngredientCatalogException::classificationMismatch($unknown, $missing);
        }

        $changed = $this->repository->classify($classification);
        $base = count(array_filter($classification));

        return new ClassificationResult(count($withoutCreator), $base, count($classification) - $base, $changed);
    }
}
