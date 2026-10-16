<?php

namespace Repas\User\Infrastructure\Http\Api\SendEmailVerification;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendEmailVerificationPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Adresse email du compte en attente", example: 'alexiane@example.com')]
        public string $email,
    ) {
    }
}
