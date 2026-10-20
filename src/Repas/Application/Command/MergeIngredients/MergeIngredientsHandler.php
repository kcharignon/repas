<?php

namespace Repas\Repas\Application\Command\MergeIngredients;


use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Interface\IngredientMergeRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\MergeParty;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientMergePlanner;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Shared\Domain\Event\IngredientRemovedEvent;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fusionne deux ingrédients d'un utilisateur : `B` (absorbé) disparaît, `A` (gardé) reçoit tout (ADR 0028 décision 9,
 * `docs/ingredients/fusion.md`). Dans l'ordre, dans la transaction du bus de commandes (tout ou rien) :
 *
 *  1. les deux ingrédients sont verrouillés ; si `B` n'existe plus, c'est peut-être un rejeu (l'alias `B -> A` existe : `204` sans rien
 *     faire) ou un ingrédient inconnu (`404`) ;
 *  2. l'utilisateur est le propriétaire des DEUX, sans exception pour un administrateur : l'ingrédient d'un autre est inconnu (`404`),
 *     un commun est refusé (`422`), le même ingrédient aussi ; une liste envoyée au drive qui utilise l'un des deux, une ligne d'un
 *     autre utilisateur sur `B` ou une unité de `B` que `A` ne sait pas convertir arrêtent la fusion avant la première écriture
 *     (`IngredientMergePlanner`, le même verdict que l'aperçu) ;
 *  3. les lignes de recettes, de listes et les choix du drive passent sur `A` (versions +1), les alias suivent, `B` et ses conversions
 *     propres sont supprimés (celles de `B` ne sont PAS reportées sur `A`) ;
 *  4. `IngredientRemovedEvent` si `B` n'est pas une adoption (la statistique « ingrédients créés » : une adoption n'a jamais compté).
 *
 * Les recettes et les listes ne sont pas hydratées (SQL en masse) : un nombre de requêtes constant, quel que soit leur nombre. Une
 * écriture de recette ou de liste qui avait lu la version avant la fusion répond `412` (ou `409` si elle enregistre en même temps), au
 * lieu de recréer une ligne sur l'ingrédient supprimé.
 */
#[AsMessageHandler]
readonly class MergeIngredientsHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private IngredientMergeRepository $mergeRepository,
        private IngredientAliasRepository $aliasRepository,
        private IngredientDistinctRepository $distinctRepository,
        private ConversionRepository $conversionRepository,
        private ConversionService $conversionService,
        private IngredientMergePlanner $planner,
        private DriveIngredientReferences $driveReferences,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws IngredientException voir les cas ci-dessus
     * @throws DepartmentException
     * @throws UnitException
     */
    public function __invoke(MergeIngredientsCommand $command): void
    {
        if ($command->absorbedSlug === $command->keptSlug) {
            throw IngredientException::cannotMergeIntoItself();
        }

        $this->mergeRepository->lock($command->absorbedSlug, $command->keptSlug);

        // Rejeu d'une fusion qui a réussi : l'ingrédient absorbé n'existe plus, son alias désigne l'ingrédient gardé
        if (!$this->ingredientRepository->exists($command->absorbedSlug)) {
            $this->assertAlreadyMerged($command);

            return;
        }

        // Chacun des deux est chargé une seule fois (ADR 0013 règle 7)
        $ingredients = $this->ingredientRepository->findBySlugs(Tab::fromArray($command->absorbedSlug, $command->keptSlug));
        $absorbed = $ingredients->find(fn(Ingredient $ingredient) => $ingredient->getSlug() === $command->absorbedSlug);
        $kept = $ingredients->find(fn(Ingredient $ingredient) => $ingredient->getSlug() === $command->keptSlug);

        // L'ingrédient d'un autre est inconnu (404) AVANT tout autre refus : on ne révèle rien de lui. Un commun n'est à personne : il est
        // refusé plus bas (422). Pas de passe-droit d'administrateur.
        foreach ([$absorbed, $kept] as $ingredient) {
            if (!$ingredient->isCommon()) {
                $ingredient->assertOwnedBy($command->userId);
            }
        }

        $usage = $this->mergeRepository->usageOf($command->userId, $absorbed->getSlug(), $kept->getSlug(), $kept->getDefaultPurchaseUnit()->getSlug());
        $plan = $this->planner->plan(
            MergeParty::of($absorbed),
            MergeParty::of($kept),
            $this->conversionService->graphOf($kept),
            $usage,
            $this->driveReferences->mergeImpact($command->userId, $absorbed->getSlug(), $kept->getSlug())->chosenByAnotherUser,
        );
        if ($plan->block !== null) {
            throw $plan->block->toException();
        }

        $this->mergeRepository->repoint($command->userId, $absorbed->getSlug(), $kept->getSlug(), $kept->getDefaultPurchaseUnit()->getSlug(), $plan->factors);
        $this->driveReferences->merge($command->userId, $absorbed->getSlug(), $kept->getSlug());
        $this->aliasRepository->mergeInto($command->userId, $absorbed->getSlug(), $kept->getSlug(), $this->clock->now());
        // Les « Pas le même » qui nommaient l'absorbé n'ont plus d'objet (et ne doivent pas être hérités par un ingrédient qui reprendrait son slug)
        $this->distinctRepository->deleteByIngredientSlug($command->userId, $absorbed->getSlug());

        // Les conversions propres de B ne sont pas reportées sur A : l'utilisateur ne peut pas en créer, ce serait une écriture silencieuse
        $this->conversionRepository->deleteByIngredient($absorbed);
        $this->ingredientRepository->delete($absorbed);

        // Une adoption n'a jamais été comptée comme une création : la supprimer ne décrémente pas la statistique (ADR 0028 décision 7)
        if (!$absorbed->isAdoption()) {
            $this->eventDispatcher->dispatch(new IngredientRemovedEvent($command->userId, $absorbed->getSlug()));
        }
    }

    /**
     * Le rejeu d'une fusion réussie répond comme elle : rien à faire. Tout autre cas est un ingrédient inconnu : l'alias n'existe pas,
     * désigne un autre ingrédient (l'absorbé a été fusionné ailleurs) ou un ingrédient qui n'est plus à l'utilisateur.
     *
     * @throws IngredientException
     */
    private function assertAlreadyMerged(MergeIngredientsCommand $command): void
    {
        $alias = $this->aliasRepository->find($command->userId, $command->absorbedSlug);
        if ($alias === null || $alias->getIngredientSlug() !== $command->keptSlug || !$this->ingredientRepository->exists($command->keptSlug)) {
            throw IngredientException::notFound($command->absorbedSlug);
        }

        $this->ingredientRepository->findOneBySlug($command->keptSlug)->assertOwnedBy($command->userId);
    }
}
