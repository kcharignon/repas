<?php

namespace Repas\User\Infrastructure\Http\Api\RegisterNewUser;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de l'inscription. Le formulaire Twig n'impose pas de longueur minimale au mot de passe ; l'API publique en
 * impose une (un compte créé sans formulaire ni confirmation de saisie).
 */
final readonly class RegisterNewUserPayload
{
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
        #[Assert\Range(min: 1, max: 100)]
        #[OA\Property(description: 'Nombre de personnes proposé par défaut pour une recette', example: 4)]
        public int $defaultServing,
    ) {
    }
}
