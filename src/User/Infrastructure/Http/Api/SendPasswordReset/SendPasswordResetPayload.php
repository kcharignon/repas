<?php

namespace Repas\User\Infrastructure\Http\Api\SendPasswordReset;


use OpenApi\Attributes as OA;
use Repas\User\Infrastructure\Http\EmailRecipientPayload;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendPasswordResetPayload implements EmailRecipientPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Adresse email du compte dont le mot de passe est oublié", example: 'alexiane@example.com')]
        public string $email,
    ) {
    }

    public function recipientEmail(): string
    {
        return $this->email;
    }
}
