<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

final class RecipeType implements ModelInterface
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

    public static function create(string $name, string $image, int $order): self
    {
        $slug = StringTool::slugify($name);
        return new self($slug, $name, $image, $order);
    }

    public static function load(array $datas): self
    {
        return new self(
            slug: $datas['slug'],
            name: $datas['name'],
            image: $datas['image'],
            order: $datas['order'],
        );
    }

    public function update(string $name, string $image, int $order): void
    {
        $this->name = $name;
        $this->image = $image;
        $this->order = $order;
    }
}
