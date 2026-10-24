<?php

namespace Repas\Repas\Application\Command\HandOverCatalog;


use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientCatalogException;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Interface\IngredientCatalogHandoverRepository;
use Repas\Repas\Domain\Service\CatalogHandoverPreconditions;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Phase 4 de la passation du catalogue (ING-009, ADR 0028 amendement du 2026-10-20, `docs/ingredients/sans-commun.md` §§ 5 et 6) : les anciens
 * communs (les ingrédients sans créateur) passent au propriétaire du catalogue, EN UNE INSTRUCTION, dans la transaction du bus de commandes :
 *
 * 0. verrou consultatif de la passation ; s'il ne reste aucun ingrédient sans créateur et que ceux du fichier sont tous au propriétaire, la
 *    passation est faite : rien n'est écrit, aucun événement (idempotence) ;
 * 1. les préconditions P1 à P7 sont RELUES ICI, dans la transaction (`CatalogHandoverPreconditions`) : si l'une manque, rien n'est écrit et
 *    le message dit laquelle ;
 * 2. empreintes d'AVANT : celle des ingrédients (contenu et conversions propres), celle du propriétaire (recettes, listes, versions, listes
 *    envoyées) et celle de ses choix du drive ;
 * 3. `UPDATE ingredient SET creator = <propriétaire>` des ingrédients du fichier : seul `creator` change, aucun `INSERT`, aucun `DELETE`, aucune
 *    ligne de recette, de liste ou de choix, aucune version, aucun alias, aucune conversion (rattachées par le slug, qui ne bouge pas) ;
 * 4. empreintes d'APRÈS comparées à celles d'avant : à la moindre différence, `IngredientCatalogException`, donc ANNULATION de tout ;
 * 5. si des lignes ont changé, annonce `RecipesOrIngredientsCreatedEvent` UNE fois (le compteur « ingrédients créés » du propriétaire reflète ses
 *    anciens communs) ; un rejeu ne change aucune ligne et n'annonce rien. Le propriétaire n'est pas chargé : la statistique est mise à jour
 *    par l'écouteur, qui le relit (ADR 0013 règle 7).
 *
 * Elle ne crée ni ne supprime aucun ingrédient et ne charge aucun agrégat.
 */
#[AsMessageHandler]
readonly class HandOverCatalogHandler
{
    public function __construct(
        private IngredientCatalogHandoverRepository $repository,
        private BaseIngredientClassification $classification,
        private IngredientCatalogOwner $catalogOwner,
        private IngredientSeedingFeature $seedingFeature,
        private CatalogHandoverPreconditions $preconditions,
        private IngredientAdoptionBulkRepository $bulkRepository,
        private DriveIngredientReferences $driveReferences,
        private AdoptionVerifier $verifier,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws IngredientCatalogException une précondition manque, ou une empreinte diverge (la transaction est annulée)
     */
    public function __invoke(HandOverCatalogCommand $command): HandOverResult
    {
        $this->repository->lock();

        $classification = $this->classification->all();
        $ownerId = $this->catalogOwner->ownerId();
        $state = $this->repository->state($ownerId, $classification);
        if ($state->isHandedOver($classification)) {
            return new HandOverResult(HandOverOutcome::ALREADY_DONE);
        }

        $unmet = $this->preconditions->unmet($state, $classification, $this->seedingFeature->isEnabled());
        if ($unmet !== []) {
            throw IngredientCatalogException::preconditionsNotMet($unmet);
        }
        \assert($ownerId !== null);

        // P5 : les ingrédients sans créateur sont exactement ceux du fichier
        $slugs = $state->slugsWithoutCreator;
        $ingredientsBefore = $this->repository->fingerprint($slugs);
        $ownerBefore = $this->bulkRepository->snapshot($ownerId, []);
        $driveBefore = $this->driveReferences->fingerprint($ownerId);

        $handedOver = $this->repository->assignToOwner($ownerId, $slugs);

        // Les vérifications, DANS la transaction : un défaut annule tout
        $ingredientsAfter = $this->repository->fingerprint($slugs);
        $ownerAfter = $this->bulkRepository->snapshot($ownerId, []);
        $driveAfter = $this->driveReferences->fingerprint($ownerId);
        $problems = [];
        if ($handedOver !== count($slugs)) {
            $problems[] = sprintf('%d ingrédient(s) passés, %d attendus', $handedOver, count($slugs));
        }
        if ($ingredientsBefore !== $ingredientsAfter) {
            $problems[] = 'le contenu des ingrédients ou de leurs conversions propres a changé';
        }
        // Rien ne bouge chez le propriétaire : mêmes lignes, mêmes contenus, mêmes versions (aucune ne monte : rien n'est repointé), mêmes listes
        // envoyées (statut et date d'envoi), mêmes choix du drive, mêmes statistiques (la statistique n'est annoncée qu'après)
        array_push($problems, ...$this->verifier->discrepancies($ownerBefore, $ownerAfter, $driveBefore, $driveAfter, []));
        if (($left = count($this->repository->slugsWithoutCreator())) > 0) {
            $problems[] = sprintf('%d ingrédient(s) restent sans créateur', $left);
        }
        if ($problems !== []) {
            throw IngredientCatalogException::handoverVerificationFailed($problems);
        }

        $this->eventDispatcher->dispatch(new RecipesOrIngredientsCreatedEvent($ownerId, Tab::fromArray($slugs), Tab::fromArray([])));

        return new HandOverResult(HandOverOutcome::HANDED_OVER, $handedOver, $state->ownConversionsWithoutCreator, $ingredientsAfter, $ownerAfter->contentFingerprint());
    }
}
