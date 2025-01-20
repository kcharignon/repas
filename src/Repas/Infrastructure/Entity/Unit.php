<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repository\UnitRepository;
use Repas\Repas\Domain\Model\Unit as UnitModel;

#[ORM\Entity(repositoryClass: UnitRepository::class)]
#[ORM\Table(name: '`unit`')]
class Unit
{
    #[ORM\Id]
    #[ORM\Column(name: 'slug', length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $symbol = null;

    public function __construct(
        string $slug,
        string $name,
        string $symbol,
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->symbol = $symbol;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;

        return $this;
    }

    public static function fromModel(UnitModel $unit): static
    {
        $datas = $unit->toArray();
        return static::fromData($datas);
    }

    public static function fromData(array $datas): static
    {
        return new static(
            slug: $datas['slug'],
            name: $datas['name'],
            symbol: $datas['symbol'],
        );
    }

    public function getModel(): UnitModel
    {
        return UnitModel::load($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'symbol' => $this->symbol,
        ];
    }
}
