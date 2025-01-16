<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Tool\StringTool;

class Department
{
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

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'image' => $this->image,
        ];
    }

    public static function load(array $datas): self
    {
        return new self(
            $datas['slug'],
            $datas['name'],
            $datas['image'],
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
