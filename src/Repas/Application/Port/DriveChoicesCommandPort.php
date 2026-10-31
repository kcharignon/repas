<?php

namespace Repas\Repas\Application\Port;

use Repas\Repas\Domain\Model\DriveChoiceFingerprint;

/**
 * Ce que les écritures d'ingrédients demandent au drive : les choix de produits que les comptes y ont faits, vus par ingrédient. Quand un
 * ingrédient change de slug (amorçage d'un compte) ou disparaît dans un autre (fusion), `Repas` ne lit ni n'écrit aucune table du drive :
 * il demande, et le drive reste maître de ses tables, de sa clé unique et de ses produits mémorisés. Toutes les enseignes et tous les
 * magasins du compte sont traités ensemble. Adapté depuis le contexte `Drive` (ADR 0035).
 */
interface DriveChoicesCommandPort
{
    /**
     * L'empreinte de ce que le drive mémorise pour le compte. Lecture seule, quelques requêtes quel que soit le nombre de choix.
     */
    public function fingerprint(string $userId): DriveChoiceFingerprint;

    /**
     * Les slugs des ingrédients que les choix du compte désignent, sans doublon, triés : l'amorçage les copie aussi, en plus des
     * ingrédients « de base ».
     *
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array;

    /**
     * Fait passer les choix du compte d'un slug d'ingrédient à un autre. Un choix dont le slug cible est déjà pris dans le même magasin n'est
     * pas touché : l'appelant le voit à l'empreinte et au décompte rendu.
     *
     * @param array<string, string> $slugMap nouveau slug par ancien slug
     *
     * @return int le nombre de choix repointés
     */
    public function repoint(string $userId, array $slugMap): int;

    /**
     * La fusion de l'ingrédient absorbé dans l'ingrédient gardé pour le compte : le choix du gardé gagne là où il existe, celui de l'absorbé
     * passe sur le gardé, avec ses produits, ailleurs. Les choix d'un autre compte ne sont jamais touchés.
     */
    public function merge(string $userId, string $absorbedSlug, string $keptSlug): void;
}
