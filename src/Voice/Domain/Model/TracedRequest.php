<?php

namespace Repas\Voice\Domain\Model;


/**
 * Ce que la trace retient d'une requête d'Alexa déjà prouvée authentique : son type, le nom de l'intention, si la séance est neuve (lus AVANT toute
 * réduction de taille, donc toujours présents quand la requête les portait) et le contenu assaini par liste blanche (`AlexaTraceSanitizer`).
 */
final readonly class TracedRequest
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public ?string $type,
        public ?string $intent,
        public ?bool $sessionNew,
        public array $payload,
    ) {
    }
}
