<?php

namespace Repas\User\Infrastructure\Http\Api\UpdateUserSettings;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps du remplacement des réglages de l'utilisateur connecté. Le mot de passe n'est jamais relu : l'omettre le laisse
 * inchangé. Le changer exige le mot de passe actuel (règle du cas d'usage, pas d'ici).
 */
final readonly class UserSettingsPayload
{
    public function __construct(
        #[Assert\Range(min: 1, max: 100)]
        #[OA\Property(description: 'Nombre de personnes proposé par défaut pour une recette', example: 4)]
        public int $defaultServing,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(min: 8, max: 4096)]
        #[OA\Property(description: 'Nouveau mot de passe (8 caractères au moins) ; omis, le mot de passe ne change pas', example: 'un-nouveau-mot-de-passe', nullable: true)]
        public ?string $newPassword = null,
        #[Assert\Length(max: 4096)]
        #[OA\Property(description: 'Mot de passe actuel, obligatoire avec `newPassword`', example: 'un-mot-de-passe', nullable: true)]
        public ?string $actualPassword = null,
    ) {
    }
}
