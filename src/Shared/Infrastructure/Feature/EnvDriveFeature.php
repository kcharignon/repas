<?php

namespace Repas\Shared\Infrastructure\Feature;


use Psr\Log\LoggerInterface;
use Repas\Shared\Application\Interface\DriveFeature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit les interrupteurs dans l'environnement : `DRIVE_ENABLED` (booléen, global) et `DRIVE_RETAILERS` (DRV-702), la liste des
 * enseignes allumées, des identifiants séparés par des virgules.
 *
 * La liste est analysée une fois : espaces de bord retirés, éléments vides ignorés (`a,` vaut `a`), doublons retirés, ordre gardé. Un
 * identifiant que le registre ne connaît pas est **ignoré** et journalisé (un avertissement par identifiant, à la construction du
 * service, donc une fois au démarrage du processus) : une faute de frappe n'empêche jamais de démarrer. Une variable vide allume
 * **aucune** enseigne (`DRIVE_RETAILERS=`) ; son absence n'est pas décidée ici : le `.env` embarqué et `deploy/compose.yaml` donnent la
 * valeur par défaut. Les identifiants connus viennent du registre du contexte `Drive` par le paramètre `repas.drive.known_retailers`
 * (`config/services.yaml`) : `Shared` ne nomme aucune enseigne.
 */
final readonly class EnvDriveFeature implements DriveFeature
{
    /** @var list<string> */
    private array $retailers;

    /**
     * @param list<string> $knownRetailers
     */
    public function __construct(
        #[Autowire('%env(bool:DRIVE_ENABLED)%')]
        private bool $enabled,
        #[Autowire('%env(DRIVE_RETAILERS)%')]
        string $retailers,
        #[Autowire('%repas.drive.known_retailers%')]
        array $knownRetailers,
        LoggerInterface $logger,
    ) {
        $enabledRetailers = $seen = [];
        foreach (explode(',', $retailers) as $candidate) {
            $retailer = trim($candidate);
            if ($retailer === '' || in_array($retailer, $seen, true)) {
                continue;
            }
            $seen[] = $retailer;
            if (!in_array($retailer, $knownRetailers, true)) {
                $logger->warning('DRIVE_RETAILERS : enseigne inconnue du registre, ignorée.', ['retailer' => mb_substr($retailer, 0, 40)]);
                continue;
            }
            $enabledRetailers[] = $retailer;
        }
        $this->retailers = $enabledRetailers;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isRetailerEnabled(string $retailer): bool
    {
        return in_array($retailer, $this->retailers, true);
    }

    public function enabledRetailers(): array
    {
        return $this->retailers;
    }
}
