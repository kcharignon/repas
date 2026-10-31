<?php

namespace Repas\Repas\Application\OpenHostService;

/**
 * Ce que les listes de courses en cours d'un utilisateur contiennent pour une phrase, publié par `Repas` pour un autre contexte (ADR 0029 décision 12) :
 * pas de liste en cours (envoyée au drive ou non), le produit n'y est pas (avec le nom de l'ingrédient reconnu, s'il l'est, ou les lignes qui
 * ressemblent à une phrase qui ne désigne aucun ingrédient), un produit est porté par une ou plusieurs listes (une ligne chacune, la liste principale
 * d'abord), ou plusieurs produits se valent (même nom, rayons différents). Jamais un modèle de domaine.
 */
final readonly class ActiveListLineMatchDTO
{
    /**
     * @param bool                    $hasList     l'utilisateur a une liste en cours
     * @param list<ActiveListLineDTO> $lines       les lignes qui portent le produit (aucune : il n'y est pas ; une par liste qui le porte ; si plusieurs produits
     *                                             se valent, les lignes de chacun)
     * @param ?string                 $absentName  le nom de l'ingrédient reconnu qui n'est pas dans la liste, `null` si la phrase ne désigne aucun ingrédient
     * @param bool                    $listSent    aucune liste en cours, mais l'utilisateur en a une envoyée au drive
     * @param list<ActiveListLineDTO> $suggestions les lignes les plus proches d'une phrase qui ne désigne aucun ingrédient (au plus trois)
     */
    public function __construct(
        public bool $hasList,
        public array $lines,
        public ?string $absentName,
        public bool $listSent,
        public array $suggestions,
    ) {
    }

    /** Plusieurs PRODUITS se valent (le nom ou le rayon les distingue) : une ligne dans plusieurs listes est un seul produit. */
    public function isTied(): bool
    {
        $products = [];
        foreach ($this->lines as $line) {
            $products[$line->name . '|' . $line->departmentSlug] = true;
        }

        return count($products) > 1;
    }
}
