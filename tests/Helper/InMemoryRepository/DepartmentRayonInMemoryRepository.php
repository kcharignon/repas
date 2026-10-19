<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Drive\Domain\Interface\DepartmentRayonRepository;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Compte les choix comme la vraie table : une unité par choix, des doublons qui s'additionnent. Aucune dépendance Doctrine.
 */
class DepartmentRayonInMemoryRepository implements DepartmentRayonRepository
{
    /** @var array<string, int> nombre de choix, par `enseigne|rayon de Repas|rayon de l'enseigne` */
    private array $counts = [];

    /** @var list<int> nombre de choix reçus à chaque appel (un appel = une requête) */
    public array $recordChoicesSizes = [];

    public function recordChoices(Retailer $retailer, array $choices): void
    {
        $this->recordChoicesSizes[] = count($choices);
        foreach ($choices as $choice) {
            $key = $this->key($retailer, $choice->departmentSlug, $choice->rayonId);
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }
    }

    public function countFor(Retailer $retailer, string $departmentSlug, int $rayonId): int
    {
        return $this->counts[$this->key($retailer, $departmentSlug, $rayonId)] ?? 0;
    }

    /**
     * @return array<string, int> tous les compteurs, par `enseigne|rayon de Repas|rayon de l'enseigne`, triés par clé
     */
    public function all(): array
    {
        ksort($this->counts);

        return $this->counts;
    }

    private function key(Retailer $retailer, string $departmentSlug, int $rayonId): string
    {
        return $retailer->value . '|' . $departmentSlug . '|' . $rayonId;
    }
}
