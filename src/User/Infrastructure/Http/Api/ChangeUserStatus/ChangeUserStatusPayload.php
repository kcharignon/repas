<?php

namespace Repas\User\Infrastructure\Http\Api\ChangeUserStatus;


use OpenApi\Attributes as OA;
use Repas\User\Domain\Model\UserStatus;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeUserStatusPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [self::class, 'statuses'])]
        #[OA\Property(description: 'Nouveau statut : `PENDING` (adresse email à confirmer), `ACTIVE` ou `DISABLED`', enum: ['PENDING', 'ACTIVE', 'DISABLED'], example: 'ACTIVE')]
        public string $status,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_map(static fn(UserStatus $status): string => $status->value, UserStatus::cases());
    }
}
