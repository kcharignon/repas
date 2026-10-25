<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Dto\CreatedIngredient;
use Repas\Shared\Application\Dto\SpokenChoice;
use Repas\Shared\Application\Interface\SpokenIngredientCreator;
use Throwable;

/**
 * Double du port `SpokenIngredientCreator` : les rayons et unités programmés (ceux du catalogue par défaut), les créations demandées notées,
 * l'exception programmée au besoin. La création réelle se prouve dans `Repas` et de bout en bout.
 */
final class SpokenIngredientCreatorInMemory implements SpokenIngredientCreator
{
    /** @var list<array{string, string, string, string}> les créations demandées `[userId, nom, rayon, unité]`, dans l'ordre */
    public array $created = [];

    /** @var list<SpokenChoice> */
    private array $departments;

    /** @var list<SpokenChoice> */
    private array $units;

    private ?Throwable $failure = null;

    public function __construct()
    {
        $this->departments = [
            new SpokenChoice('boucherie', 'boucherie'),
            new SpokenChoice('fruit-et-legume', 'fruit & légume'),
            new SpokenChoice('produit-menager', 'produit ménager'),
        ];
        $this->units = [
            new SpokenChoice('kilo', 'kilo'),
            new SpokenChoice('unite', 'unité'),
            new SpokenChoice('paquet', 'paquet'),
        ];
    }

    public function willFail(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function departments(): array
    {
        return $this->departments;
    }

    public function units(): array
    {
        return $this->units;
    }

    public function create(string $userId, string $name, string $departmentSlug, string $unitSlug): CreatedIngredient
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->created[] = [$userId, $name, $departmentSlug, $unitSlug];

        $department = null;
        foreach ($this->departments as $candidate) {
            $department = $candidate->slug === $departmentSlug ? $candidate : $department;
        }

        return new CreatedIngredient($name . '-' . $userId, $name, $department->name ?? $departmentSlug);
    }
}
