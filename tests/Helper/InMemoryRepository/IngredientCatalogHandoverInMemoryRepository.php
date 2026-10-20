<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\IngredientCatalogHandoverRepository;
use Repas\Repas\Domain\Model\CatalogHandoverState;

/**
 * Le port de la passation du catalogue (ING-009) sur de simples tableaux, pour tester l'orchestration des handlers sans base : les ingrédients
 * (créateur et marqueur), et ce que la base dirait d'autre (le propriétaire, les lignes des autres comptes, les comptes actifs, les orphelins)
 * posé directement par le test. Le SQL est prouvé sur la vraie base (`tests/Repas/OutputAdapter/IngredientCatalogHandoverRepositoryTest`).
 *
 * L'état est public. Une transaction n'existe pas ici : une erreur du handler ne défait rien, c'est le bus de commandes réel qui l'annule
 * (prouvé sur la base).
 */
final class IngredientCatalogHandoverInMemoryRepository implements IngredientCatalogHandoverRepository
{
    /** @var array<string, array{creator: ?string, base: bool}> par slug */
    public array $ingredients = [];
    public ?string $ownerStatus = 'ACTIVE';
    public bool $ownerIsAdministrator = true;
    public int $administrators = 1;
    public int $withOrigin = 0;
    public int $accountFormat = 0;
    public int $ownConversions = 0;
    /** @var array<string, array<string, int>> */
    public array $foreignLinesByAccount = [];
    /** @var array<string, int> */
    public array $ownerLinesOnOthers = [];
    /** @var list<string> */
    public array $activeNotSeeded = [];
    /** @var list<string> */
    public array $activeWithoutIngredient = [];
    /** @var array<string, int> */
    public array $orphans = [];
    /** Quand vrai, l'empreinte d'après diffère de celle d'avant : le handler doit le voir. */
    public bool $corruptFingerprintAfterAssign = false;
    /** Quand défini, `assignToOwner()` annonce ce nombre au lieu du vrai. */
    public ?int $assignedCount = null;
    /** Quand défini, appelé juste après l'écriture : simule ce que la base changerait d'autre pendant la passation. */
    public ?\Closure $afterAssign = null;
    /** @var list<string> les appels, dans l'ordre */
    public array $calls = [];

    private bool $assigned = false;

    /**
     * @param array<string, bool> $baseBySlug
     */
    public function withoutCreator(array $baseBySlug): self
    {
        foreach ($baseBySlug as $slug => $base) {
            $this->ingredients[$slug] = ['creator' => null, 'base' => $base];
        }

        return $this;
    }

    public function lock(): void
    {
        $this->calls[] = 'lock';
    }

    public function slugsWithoutCreator(): array
    {
        $slugs = array_keys(array_filter($this->ingredients, static fn(array $i): bool => $i['creator'] === null));
        sort($slugs, SORT_STRING);

        return array_map('strval', $slugs);
    }

    public function state(?string $ownerId, array $classification): CatalogHandoverState
    {
        $this->calls[] = 'state';
        $without = $this->slugsWithoutCreator();
        $atOwner = [];
        foreach ($classification as $slug => $base) {
            if ($ownerId !== null && ($this->ingredients[$slug]['creator'] ?? null) === $ownerId) {
                $atOwner[$slug] = $this->ingredients[$slug]['base'];
            }
        }

        return new CatalogHandoverState(
            ownerId: $ownerId,
            ownerStatus: $ownerId === null ? null : $this->ownerStatus,
            ownerIsAdministrator: $ownerId !== null && $this->ownerIsAdministrator,
            administrators: $this->administrators,
            slugsWithoutCreator: $without,
            markersWithoutCreator: array_combine($without, array_map(fn(string $slug): bool => $this->ingredients[$slug]['base'], $without)),
            withoutCreatorWithOrigin: $this->withOrigin,
            withoutCreatorAccountFormat: $this->accountFormat,
            ownConversionsWithoutCreator: $this->ownConversions,
            fileSlugsAtOwner: $atOwner,
            foreignLinesByAccount: $this->foreignLinesByAccount,
            ownerLinesOnOthers: $this->ownerLinesOnOthers,
            activeNotSeeded: $this->activeNotSeeded,
            activeWithoutIngredient: $this->activeWithoutIngredient,
            orphans: $this->orphans,
        );
    }

    public function classify(array $classification): int
    {
        $this->calls[] = 'classify';
        $changed = 0;
        foreach ($classification as $slug => $base) {
            if ($this->hasNoCreator($slug) && $this->ingredients[$slug]['base'] !== $base) {
                $this->ingredients[$slug]['base'] = $base;
                ++$changed;
            }
        }

        return $changed;
    }

    public function assignToOwner(string $ownerId, array $slugs): int
    {
        $this->calls[] = 'assignToOwner';
        $count = 0;
        foreach ($slugs as $slug) {
            if ($this->hasNoCreator($slug)) {
                $this->ingredients[$slug]['creator'] = $ownerId;
                ++$count;
            }
        }
        $this->assigned = true;
        if ($this->afterAssign !== null) {
            ($this->afterAssign)();
        }

        return $this->assignedCount ?? $count;
    }

    private function hasNoCreator(string $slug): bool
    {
        return isset($this->ingredients[$slug]) && $this->ingredients[$slug]['creator'] === null;
    }

    public function fingerprint(array $slugs): string
    {
        sort($slugs, SORT_STRING);

        return md5(implode(',', $slugs)) . ($this->corruptFingerprintAfterAssign && $this->assigned ? '-corrompue' : '');
    }
}
