<?php

namespace Repas\Drive\Infrastructure\Http\Api\MarkLinesInBasket;

use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\MarkLinesInBasket\NotAddedLine;
use Repas\Drive\Domain\Model\NotAddedOutcome;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une ligne que le passage n'a PAS mise au panier de l'enseigne (ADR 0041) : l'ingrédient, l'issue que l'agent rapporte et, quand elle est connue, la
 * raison. Le corps vient d'un site qu'on ne contrôle pas : la raison est un code fixe, jamais un texte libre.
 */
final readonly class NotAddedLinePayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[a-z0-9-]{1,255}$/D')]
        #[OA\Property(description: "Slug de l'ingrédient de la ligne ; un slug qui n'est pas dans la liste est ignoré, un ancien slug désigne l'ingrédient actuel", pattern: '^[a-z0-9-]{1,255}$', example: 'farine')]
        public string $ingredientSlug,
        #[OA\Property(description: "Ce qui est arrivé à la ligne : `NOT_IN_CART` (produit choisi que le panier n'a pas pris), `UNAVAILABLE` (aucun produit disponible), `SKIPPED` (passé cette fois), `HAVE_AT_HOME` (déjà à la maison), `FAILED` (erreur)", enum: ['NOT_IN_CART', 'UNAVAILABLE', 'SKIPPED', 'HAVE_AT_HOME', 'FAILED'], example: 'NOT_IN_CART')]
        public NotAddedOutcome $outcome,
        #[Assert\Regex(pattern: NotAddedLine::REASON_PATTERN)]
        #[OA\Property(description: "La raison quand l'agent la connaît : un code en majuscules de 40 caractères au plus (le code de l'erreur de l'agent), jamais un texte libre", pattern: '^[A-Z][A-Z0-9_]{0,39}$', example: 'BLOCKED', nullable: true)]
        public ?string $reason = null,
    ) {
    }

    public function toLine(): NotAddedLine
    {
        return new NotAddedLine($this->ingredientSlug, $this->outcome, $this->reason);
    }
}
