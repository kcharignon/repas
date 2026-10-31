<?php

namespace Repas\Repas\Application\OpenHostService;

/**
 * Ce que la liste de courses active d'un utilisateur contient pour une phrase, publié par `Repas` pour un autre contexte (ADR 0029 décision 12) :
 * pas de liste active (envoyée au drive ou non), le produit n'y est pas (avec le nom de l'ingrédient reconnu, s'il l'est, ou les lignes qui
 * ressemblent à une phrase qui ne désigne aucun ingrédient), une ligne le porte, ou plusieurs lignes se valent (même nom, rayons différents).
 * Jamais un modèle de domaine.
 */
final readonly class ActiveListLineMatchDTO
{
    /**
     * @param bool                    $hasList     l'utilisateur a une liste active
     * @param list<ActiveListLineDTO> $lines       les lignes de la liste qui portent le produit (aucune : il n'y est pas ; au moins deux : elles se valent)
     * @param ?string                 $absentName  le nom de l'ingrédient reconnu qui n'est pas dans la liste, `null` si la phrase ne désigne aucun ingrédient
     * @param bool                    $listSent    aucune liste active, mais l'utilisateur en a une envoyée au drive
     * @param list<ActiveListLineDTO> $suggestions les lignes de la liste les plus proches d'une phrase qui ne désigne aucun ingrédient (au plus trois)
     */
    public function __construct(
        public bool $hasList,
        public array $lines,
        public ?string $absentName,
        public bool $listSent,
        public array $suggestions,
    ) {
    }
}
