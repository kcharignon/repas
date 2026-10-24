<?php

namespace Repas\User\Infrastructure\Http\Api\UpdateUserAppVersion;


use OpenApi\Attributes as OA;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de l'annonce de la version de l'application (DRV-637). Rien d'autre n'est lu : ni identifiant d'appareil, ni modèle de
 * téléphone, ni utilisateur (il vient du jeton).
 */
final readonly class UpdateUserAppVersionPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [self::class, 'platforms'])]
        #[OA\Property(description: "Plateforme de l'application installée : `android` seulement", enum: ['android'], example: 'android')]
        public string $platform,
        #[Assert\NotBlank]
        #[Assert\Length(max: AppVersion::MAX_LENGTH)]
        #[Assert\Regex(pattern: AppVersion::PATTERN)]
        #[OA\Property(description: "Version de l'application : trois nombres séparés par des points, 20 caractères au plus (ni suffixe, ni quatrième nombre)", maxLength: 20, pattern: '^[0-9]+\.[0-9]+\.[0-9]+$', example: '0.1.8')]
        public string $version,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function platforms(): array
    {
        return array_map(static fn(AppPlatform $platform): string => $platform->value, AppPlatform::cases());
    }
}
