<?php

namespace Repas\Repas\Application\Port;

use Repas\Repas\Domain\Model\DriveChoiceFingerprint;
use Repas\Repas\Domain\Model\DriveMergeImpact;

/**
 * Ce que les lectures d'ingrédients (aperçu de la fusion, rapport de l'amorçage) demandent au drive : les choix de produits des comptes,
 * vus par ingrédient. Lecture seule. Adapté depuis le contexte `Drive` (ADR 0035).
 */
interface DriveChoicesQueryPort
{
    /**
     * L'empreinte de ce que le drive mémorise pour le compte, quelques requêtes quel que soit le nombre de choix.
     */
    public function fingerprint(string $userId): DriveChoiceFingerprint;

    /**
     * Les slugs des ingrédients que les choix du compte désignent, sans doublon, triés.
     *
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array;

    /**
     * Les slugs que les choix de CHAQUE compte désignent : `[identifiant d'utilisateur => slugs triés, sans doublon]`, en une requête. La
     * requête de santé de l'amorçage vérifie qu'aucun choix ne désigne l'ingrédient d'un autre compte.
     *
     * @return array<string, list<string>>
     */
    public function referencedSlugsOfEveryUser(): array;

    /**
     * Ce que la fusion de l'ingrédient absorbé dans l'ingrédient gardé ferait des choix du compte, sans rien écrire.
     */
    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): DriveMergeImpact;
}
