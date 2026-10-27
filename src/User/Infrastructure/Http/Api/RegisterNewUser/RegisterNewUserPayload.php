<?php

namespace Repas\User\Infrastructure\Http\Api\RegisterNewUser;


use OpenApi\Attributes as OA;
use Repas\User\Infrastructure\Http\EmailRecipientPayload;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de l'inscription. L'API publique impose une longueur minimale au mot de passe (un compte créé sans
 * confirmation de saisie).
 */
final readonly class RegisterNewUserPayload implements EmailRecipientPayload
{
    private const string SERVING = 'Le nombre de personnes doit être un nombre entier de 1 à 100.';

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Adresse email, unique : sert d'identifiant de connexion", example: 'alexiane@example.com')]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        #[OA\Property(description: 'Mot de passe en clair (8 caractères au moins)', example: 'un-mot-de-passe')]
        public string $password,
        // `mixed` : la valeur reçue est jugée telle quelle (un entier JSON, rien d'autre) ; un type `int` ferait convertir une décimale par PHP avant tout contrôle
        #[Assert\Sequentially([
            new Assert\NotNull(message: self::SERVING),
            new Assert\Type(type: 'integer', message: self::SERVING),
            new Assert\Range(min: 1, max: 100, notInRangeMessage: self::SERVING),
        ])]
        #[OA\Property(description: 'Nombre de personnes proposé par défaut pour une recette', type: 'integer', example: 4, minimum: 1, maximum: 100)]
        public mixed $defaultServing,
    ) {
    }

    public function recipientEmail(): string
    {
        return $this->email;
    }
}
