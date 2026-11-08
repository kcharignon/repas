<?php

namespace Repas\Repas\Application\Command\StoreImageFromUrl;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageDownloadLimiter;
use Repas\Repas\Domain\Interface\ImageFetcher;
use Repas\Repas\Domain\Interface\ImageStorage;
use Repas\Repas\Domain\Model\ImageUrl;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Télécharge l'image d'un lien direct, la garde et rend son chemin (ADR 0039). Dans l'ordre : le lien est jugé STRICTEMENT (`ImageUrl::parse`, aucun
 * appel sortant tant qu'il est refusé), le plafond des téléchargements est consommé, l'image est téléchargée (`ImageFetcher`, qui ne parle jamais à
 * une adresse non publique), puis elle passe par EXACTEMENT le même traitement qu'un fichier envoyé (`ImageNormalizer`, `ImageStorage`). Un refus
 * n'écrit rien.
 */
#[AsMessageHandler]
readonly class StoreImageFromUrlHandler
{
    public function __construct(
        private ImageDownloadLimiter $limiter,
        private ImageFetcher $fetcher,
        private ImageNormalizer $normalizer,
        private ImageStorage $storage,
    ) {
    }

    /**
     * @return string le chemin de l'image, relatif à la racine publique
     *
     * @throws ImageException `urlRefused`, `downloadFailed`, `tooLarge`, `notAnImage`, `unsupported`, `processingFailed` ou `storageFailed`
     * @throws RateLimitedException le plafond de téléchargements de l'administrateur est atteint (rien n'est téléchargé)
     */
    public function __invoke(StoreImageFromUrlCommand $command): string
    {
        $url = ImageUrl::parse($command->url);

        $this->limiter->consume($command->userId);

        return $this->storage->store($this->normalizer->normalize($this->fetcher->fetch($url)));
    }
}
