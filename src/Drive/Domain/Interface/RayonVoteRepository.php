<?php

namespace Repas\Drive\Domain\Interface;

use Repas\Drive\Domain\Model\RayonVote;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Ce que Repas apprend des choix de produits (DRV-642) : pour chaque rayon de Repas, dans quels rayons de l'enseigne les comptes
 * choisissent leurs produits. Un compte a au plus un vote par couple `(enseigne, rayon de Repas, rayon de l'enseigne)` ; le classement
 * proposé dans le plan compte les comptes distincts. Les votes d'un compte partent avec lui. La lecture (les rayons les plus choisis,
 * pour le plan) est un loader, pas ce port.
 */
interface RayonVoteRepository
{
    /**
     * Enregistre ces votes pour ce compte : un par couple `(rayon de Repas, rayon de l'enseigne)`, créé au besoin ; un couple déjà voté
     * ou répété dans la liste ne change rien. Au-delà de `RayonVote::MAX_PER_USER_AND_RETAILER` couples pour ce compte et cette
     * enseigne, les couples nouveaux sont ignorés. Une seule requête quelle que soit la taille de la liste ; une liste vide n'en coûte aucune.
     *
     * @param list<RayonVote> $votes
     */
    public function record(string $userId, Retailer $retailer, array $votes): void;

    /**
     * Efface tous les votes du compte, toutes enseignes (suppression du compte, ADR 0021).
     */
    public function deleteByUser(string $userId): void;
}
