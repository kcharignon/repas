<?php

namespace Repas\Drive\Domain\Service;


use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Selection;
use Repas\Drive\Domain\Model\SelectionMode;

/**
 * La règle de choix de l'enseigne (docs/drive/multi-enseignes.md § 5.1, ADR 0027 décision 5, DRV-703), **calculée une fois par le back** pour
 * que le web et tout futur client appliquent la même. Fonction pure, sans état : elle ne sait rien d'une enseigne en particulier.
 *
 * Une enseigne est **candidate** quand elle est allumée (`DRIVE_RETAILERS`) ET configurée (la personne y a enregistré un magasin).
 *
 * - R1 aucune candidate : `NONE` (rien à utiliser, mener la personne à ses réglages) ;
 * - R2 une seule candidate : `AUTO` sur elle, même si un drive habituel ou une dernière enseigne désignent une autre enseigne ;
 * - R3 deux candidates ou plus : toujours `ASK`, jamais `AUTO` (décision du propriétaire du 2026-10-30, qui remplace « AUTO sur le drive
 *   habituel » : qui a configuré plusieurs drives choisit au moment d'envoyer) ;
 * - R4 la présélection d'un `ASK` (celle qui est cochée) : le drive habituel s'il est candidat (même quand la dernière utilisée est une
 *   autre), sinon la dernière utilisée si elle est candidate, sinon la première configurée (la plus anciennement enregistrée) ;
 * - R5 une enseigne éteinte n'est jamais candidate, et un drive habituel éteint est ignoré (retour à la dernière utilisée), jamais une erreur.
 */
final readonly class RetailerSelection
{
    /**
     * @param list<Retailer> $enabled    les enseignes allumées
     * @param list<Retailer> $configured les enseignes où la personne a un magasin, la plus anciennement enregistrée d'abord (l'ordre sert
     *                                   de repli à la présélection et à l'ordre de `candidates`)
     */
    public function resolve(array $enabled, array $configured, ?Retailer $default, ?Retailer $lastUsed): Selection
    {
        $candidates = [];
        foreach ($configured as $retailer) {
            if (in_array($retailer, $enabled, true) && !in_array($retailer, $candidates, true)) {
                $candidates[] = $retailer;
            }
        }

        if ($candidates === []) {
            return new Selection(SelectionMode::NONE, null, []);
        }
        if (count($candidates) === 1) {
            return new Selection(SelectionMode::AUTO, $candidates[0], $candidates);
        }
        if ($default !== null && in_array($default, $candidates, true)) {
            return new Selection(SelectionMode::ASK, $default, $candidates);
        }

        return new Selection(SelectionMode::ASK, $lastUsed !== null && in_array($lastUsed, $candidates, true) ? $lastUsed : $candidates[0], $candidates);
    }

    /**
     * L'enseigne d'une demande qui n'en nomme aucune (`plans` et `estimates` sans `?retailer=`, les anciens champs `retailer` et `store` des
     * réglages) : celle de la sélection ; sans candidate (`NONE`), l'enseigne par défaut du registre si elle est allumée, sinon la première
     * allumée, sinon l'enseigne par défaut (la demande répondra alors `404 DRIVE_RETAILER_DISABLED`, comme avant). Ainsi une personne qui n'a
     * rien configuré reçoit les mêmes réponses qu'avant la sélection.
     *
     * @param list<Retailer> $enabled
     */
    public function retailerToUse(Selection $selection, array $enabled): Retailer
    {
        if ($selection->retailer !== null) {
            return $selection->retailer;
        }

        return in_array(Retailer::default(), $enabled, true) ? Retailer::default() : ($enabled[0] ?? Retailer::default());
    }
}
