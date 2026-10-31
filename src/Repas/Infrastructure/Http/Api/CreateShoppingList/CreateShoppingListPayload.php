<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps facultatif de la création d'une liste : le type de la liste (ADR 0036). Sans corps, ou sans type, c'est la liste principale.
 */
final readonly class CreateShoppingListPayload
{
    public function __construct(
        // `mixed` : la valeur reçue est jugée telle quelle (un texte JSON ou rien), sans conversion
        #[Assert\Type(type: 'string', message: PayloadMessages::LIST_TYPE_ID)]
        #[Assert\Length(min: 1, max: 36, minMessage: PayloadMessages::LIST_TYPE_ID, maxMessage: PayloadMessages::LIST_TYPE_ID)]
        #[OA\Property(description: "Identifiant du type de la liste (`GET /api/v1/shopping-list-types`) ; absent ou null : la liste principale. La liste porte le nom du type. Un type qui n'est pas à l'utilisateur est `404`", type: 'string', nullable: true, example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public mixed $typeId = null,
    ) {
    }
}
