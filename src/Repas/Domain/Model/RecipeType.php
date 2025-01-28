<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

class RecipeType implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $slug,
        private string $name,
        private string $image,
        private int    $order,
    ) {
    }

    public function getId(): string
    {
        return $this->slug;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function setImage(string $image): void
    {
        $this->image = $image;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function setOrder(int $order): void
    {
        $this->order = $order;
    }

    public static function create(string $name, string $image, int $sequence): self
    {
        $slug = StringTool::slugify($name);
        return new self($slug, $name, $image, $sequence);
    }

    public static function load(array $datas): static
    {
        return new self(
            slug: $datas['slug'],
            name: $datas['name'],
            image: $datas['image'],
            order: $datas['order'],
        );
    }

    public function isEqual(RecipeType $type): bool
    {
        return $this->slug === $type->getSlug();
    }
}
