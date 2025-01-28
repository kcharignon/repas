<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Department;
use Repas\Shared\Domain\Tool\StringTool;

class DepartmentBuilder implements Builder
{
    private string $slug;
    private string $name;
    private string $image;

    private function initialize(): void
    {
        $this->slug ??= 'maxi-outils';
        $this->name ??= 'Maxi Outils';
        $this->image ??= 'file://images/maxi-outils.jpg';
    }

    public function setSlug(string $slug): DepartmentBuilder
    {
        $this->slug = $slug;
        return $this;
    }

    public function setName(string $name): DepartmentBuilder
    {
        $this->name = $name;
        $this->slug ??= StringTool::slugify($name);
        $this->image ??= "file://images/$this->slug.jpg";
        return $this;
    }

    public function setImage(string $image): DepartmentBuilder
    {
        $this->image = $image;
        return $this;
    }

    public function build(): Department
    {
        $this->initialize();
        return Department::load([
            "slug" => $this->slug,
            "name" => $this->name,
            "image" => $this->image,
        ]);
    }
}
