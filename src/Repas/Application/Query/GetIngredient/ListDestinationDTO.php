<?php

namespace Repas\Repas\Application\Query\GetIngredient;

use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Service\ListDestinationResolver;

/**
 * Le choix de l'utilisateur sur la liste où va un produit (ADR 0036) : la fiche du produit le montre, le réglage `PUT …/list-destination` l'écrit.
 */
final readonly class ListDestinationDTO
{
    public const string DEFAULT = 'DEFAULT';
    public const string MAIN = 'MAIN';
    public const string TYPE = 'TYPE';

    public function __construct(
        #[OA\Property(description: "`DEFAULT` : le produit va dans la liste de son rayon (ou la principale si son rayon n'a pas de type) ; `MAIN` : toujours la liste principale ; `TYPE` : toujours la liste du type `typeId`. Un choix qui désigne un type qui n'existe plus se lit `DEFAULT`", enum: [self::DEFAULT, self::MAIN, self::TYPE], example: 'DEFAULT')]
        public string $mode,
        #[OA\Property(description: "Identifiant du type de liste choisi ; `null` hors du mode `TYPE`. Clé toujours écrite", type: 'string', nullable: true)]
        public ?string $typeId,
        #[OA\Property(description: 'Nom du type de liste choisi ; `null` hors du mode `TYPE`. Clé toujours écrite', type: 'string', nullable: true)]
        public ?string $typeName,
    ) {
    }

    /**
     * @param string|null $choice   `ingredient.list_destination`
     * @param string|null $typeId   l'identifiant du type du compte qui porte ce choix, `null` si ce type n'existe pas (ou plus)
     * @param string|null $typeName son nom
     */
    public static function of(?string $choice, ?string $typeId, ?string $typeName): self
    {
        if ($choice === ListDestinationResolver::MAIN) {
            return new self(self::MAIN, null, null);
        }
        if ($choice !== null && $typeId !== null && $choice === $typeId) {
            return new self(self::TYPE, $typeId, $typeName);
        }

        return new self(self::DEFAULT, null, null);
    }
}
