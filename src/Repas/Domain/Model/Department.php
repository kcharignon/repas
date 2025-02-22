<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

final class Department implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $slug,
        private string $name,
        private string $image,
    ) {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getId(): string
    {
        return $this->slug;
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

    public function update(string $name, string $image): void
    {
        $this->name = $name;
        $this->image = $image;
    }

    public static function load(array $datas): self
    {
        return new self(
            slug: $datas['slug'],
            name: $datas['name'],
            image: $datas['image'],
        );
    }

    public static function create(string $name, string $image): self
    {
        return new self(
            StringTool::slugify($name),
            $name,
            $image,
        );
    }
}
