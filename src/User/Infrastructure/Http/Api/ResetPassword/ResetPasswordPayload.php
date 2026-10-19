<?php

namespace Repas\User\Infrastructure\Http\Api\ResetPassword;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ResetPasswordPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 256)]
        #[OA\Property(description: "Secret du lien reçu par email (paramètre `token` de l'adresse du lien)", example: '3f9c0a5e7d1b4c6a8e2f0b9d7c5a3e1f3f9c0a5e7d1b4c6a8e2f0b9d7c5a3e1f')]
        public string $token,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        #[OA\Property(description: 'Nouveau mot de passe en clair (8 caractères au moins, comme à l\'inscription)', example: 'un-mot-de-passe')]
        public string $password,
    ) {
    }
}
