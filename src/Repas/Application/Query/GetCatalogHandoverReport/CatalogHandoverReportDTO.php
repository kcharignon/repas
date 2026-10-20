<?php

namespace Repas\Repas\Application\Query\GetCatalogHandoverReport;

use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Domain\Model\HandoverPrecondition;

/**
 * Le rapport de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` § 6). Des nombres, des sommes de contrôle et des références
 * courtes : jamais une adresse, un identifiant de compte ni un nom d'ingrédient personnel (la sortie de la commande est conservée dans des
 * journaux). Les anciens communs sont des ingrédients de référence publics, mais le rapport n'en montre que les comptes.
 */
final readonly class CatalogHandoverReportDTO
{
    /**
     * @param ?string $ownerReference la référence courte du propriétaire (8 premiers caractères du SHA-256 de son identifiant)
     * @param int $fileEntries les ingrédients du fichier de classement
     * @param int $fileBase dont « de base »
     * @param string $fileChecksum la somme de contrôle (MD5) des slugs du fichier, triés (ordre binaire), séparés par des virgules
     * @param string $fileBaseChecksum idem pour ceux « de base »
     * @param int $withoutCreator les ingrédients sans créateur (les anciens communs qui restent à passer)
     * @param bool $catalogAtOwner les anciens communs sont déjà au propriétaire (`catalog*` décrit alors ceux du fichier chez lui, sinon les sans-créateur)
     * @param int $catalogCount le nombre d'anciens communs là où ils sont
     * @param string $catalogChecksum la somme de contrôle de leurs slugs (attendue : celle du fichier)
     * @param int $catalogMarked dont ceux qui portent le marqueur « de base » aujourd'hui
     * @param string $catalogMarkedChecksum la somme de contrôle des slugs de ceux-là (attendue, classement posé : celle de « de base » du fichier)
     * @param int $unknown sans créateur et absents du fichier (un commun à classer d'abord)
     * @param int $missing dans le fichier et pas sans créateur (hors passation faite)
     * @param int $markersToWrite sans créateur dont le marqueur diffère du fichier (ce que la phase 1 écrirait)
     * @param int $withOrigin sans créateur avec une origine (`adopted_from`) : un ancien commun n'en a jamais
     * @param int $accountFormat sans créateur dont le slug finit par un identifiant de compte
     * @param int $ownConversions leurs conversions propres (rattachées par le slug : elles ne changent pas)
     * @param int $atOwner les ingrédients du fichier qui sont DÉJÀ au propriétaire (sans origine)
     * @param int $atOwnerMarkersDiffering dont le marqueur diffère du fichier (le propriétaire a le droit de le changer : information)
     * @param bool $handedOver la passation est faite
     * @param bool $seedingEnabled l'amorçage à l'activation est allumé
     * @param list<string> $unknownSample quelques slugs sans créateur absents du fichier (jamais un slug au format d'un compte)
     * @param list<string> $missingSample quelques slugs du fichier absents des ingrédients sans créateur
     * @param list<HandoverPrecondition> $preconditions P1 à P7
     * @param SeedingReportDTO $seeding l'amorçage des comptes actifs sans ingrédients, lu comme si le classement était posé
     * @param list<string> $blockingAnomalies ce qui interdit d'écrire
     */
    public function __construct(
        public bool $ownerExists,
        public ?string $ownerReference,
        public ?string $ownerStatus,
        public bool $ownerIsAdministrator,
        public int $administrators,
        public int $fileEntries,
        public int $fileBase,
        public string $fileChecksum,
        public string $fileBaseChecksum,
        public int $withoutCreator,
        public bool $catalogAtOwner,
        public int $catalogCount,
        public string $catalogChecksum,
        public int $catalogMarked,
        public string $catalogMarkedChecksum,
        public int $unknown,
        public int $missing,
        public int $markersToWrite,
        public int $withOrigin,
        public int $accountFormat,
        public int $ownConversions,
        public int $atOwner,
        public int $atOwnerMarkersDiffering,
        public bool $handedOver,
        public bool $seedingEnabled,
        public array $unknownSample,
        public array $missingSample,
        public array $preconditions,
        public SeedingReportDTO $seeding,
        public array $blockingAnomalies,
    ) {
    }

    /**
     * Les ingrédients sans créateur sont EXACTEMENT ceux du fichier (la phase 1 peut classer).
     */
    public function matchesTheFile(): bool
    {
        return $this->withoutCreator > 0 && $this->unknown === 0 && $this->missing === 0;
    }

    /**
     * Le classement est déjà posé : la phase 1 n'écrirait rien.
     */
    public function isClassified(): bool
    {
        return $this->handedOver || ($this->matchesTheFile() && $this->markersToWrite === 0);
    }

    /**
     * Toutes les préconditions sont remplies aujourd'hui : la passation (phase 4) pourrait partir.
     */
    public function isReadyToHandOver(): bool
    {
        return $this->unmet() === [];
    }

    /**
     * Elles le seront une fois le classement posé et l'amorçage fait (phases 1 et 2) : un `--apply` complet peut partir.
     */
    public function isReadyToHandOverAfterSeeding(): bool
    {
        return array_filter($this->preconditions, static fn(HandoverPrecondition $precondition): bool => !$precondition->metAfterSeeding) === [];
    }

    /**
     * @return list<HandoverPrecondition> celles qui manquent aujourd'hui
     */
    public function unmet(): array
    {
        return array_values(array_filter($this->preconditions, static fn(HandoverPrecondition $precondition): bool => !$precondition->met));
    }

    /**
     * @return list<HandoverPrecondition> celles qui manqueraient encore une fois l'amorçage fait
     */
    public function unmetAfterSeeding(): array
    {
        return array_values(array_filter($this->preconditions, static fn(HandoverPrecondition $precondition): bool => !$precondition->metAfterSeeding));
    }

    public function hasBlockingAnomalies(): bool
    {
        return $this->blockingAnomalies !== [];
    }

    /**
     * Rien à classer, rien à amorcer, rien à passer.
     */
    public function isNothingToDo(): bool
    {
        return $this->handedOver && $this->seeding->isNothingToDo();
    }

    /**
     * Le verdict de `--check` : la passation est faite, plus rien à amorcer, la requête de santé est verte et aucune anomalie ne bloque.
     */
    public function isConform(): bool
    {
        return $this->isNothingToDo() && !$this->hasBlockingAnomalies() && $this->seeding->health->isHealthy();
    }
}
