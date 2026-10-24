<?php

namespace Repas\Repas\Domain\Model;


/**
 * Un ingrédient du catalogue du propriétaire qu'un compte va recevoir en copie à son amorçage (ADR 0028, amendement du 2026-10-20, ING-008) :
 * son slug et son nom (de quoi calculer le slug de la copie, `IngredientSlugPolicy::forAdoption`) et la raison pour laquelle il est copié.
 * Une valeur, pas un agrégat : le catalogue n'est jamais hydraté.
 *
 * `isBase` : l'ingrédient porte le marqueur « de base » (la copie que reçoit tout compte). Faux : il n'est copié que parce que les lignes
 * du compte (recettes, listes, choix du drive) le référencent, au rattrapage.
 *
 * `existingCopySlug` : le compte a DÉJÀ un ingrédient né de celui-là (`adopted_from`) et strictement conforme à lui (une copie de recette
 * qui a retenu l'origine, un retour arrière) mais sans alias : l'amorçage le désigne au lieu de créer un doublon.
 */
final readonly class IngredientToSeed
{
    public function __construct(
        public string $slug,
        public string $name,
        public bool $isBase = true,
        public ?string $existingCopySlug = null,
    ) {
    }
}
