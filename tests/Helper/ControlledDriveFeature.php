<?php

namespace Repas\Tests\Helper;


use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;

/**
 * Interrupteurs du drive à la main : `new ControlledDriveFeature(false)` pour essayer le cas coupé, `new ControlledDriveFeature(true,
 * [])` pour essayer le cas où aucune enseigne n'est allumée, sans toucher à l'environnement. Sans liste, toutes les enseignes du registre
 * sont allumées (ce que fait la production d'aujourd'hui).
 *
 * Un service déjà utilisé par une requête ne se remplace plus dans le conteneur de test : pour changer d'état entre deux requêtes d'un
 * même test, on pose le double une fois puis on le modifie (`switchRetailers()`, `switchEnabled()`).
 */
final class ControlledDriveFeature implements DriveFeature
{
    /** @var list<string> */
    private array $retailers;

    /**
     * @param list<string>|null $retailers les enseignes allumées ; `null` : toutes celles du registre
     */
    public function __construct(private bool $enabled = true, ?array $retailers = null)
    {
        $this->retailers = $retailers ?? Retailer::IDS;
    }

    /**
     * @param list<string>|null $retailers les enseignes allumées ; `null` : toutes celles du registre
     */
    public function switchRetailers(?array $retailers): void
    {
        $this->retailers = $retailers ?? Retailer::IDS;
    }

    public function switchEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
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
