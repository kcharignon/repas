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

    public function isBaby(): DepartmentBuilder
    {
        $this->name = 'bébé';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "images/department/bebe.png";
        return $this;
    }

    public function isAnimal(): DepartmentBuilder
    {
        $this->name = 'animal';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "https://cdn-icons-png.flaticon.com/128/2619/2619232.png";
        return $this;
    }

    public function isConserve(): DepartmentBuilder
    {
        $this->name = 'conserve';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "https://cdn-icons-png.flaticon.com/128/2916/2916046.png";
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

    public function isCereal(): self
    {
        $this->name = 'céréale';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "https://cdn-icons-png.flaticon.com/128/5009/5009812.png";
        return $this;
    }

    public function isMiscellaneous(): self
    {
        $this->name = 'divers';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "https://cdn-icons-png.flaticon.com/128/699/699044.png";
        return $this;
    }

    public function isMeat(): self
    {
        $this->name = 'viande';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "https://cdn-icons-png.flaticon.com/128/1134/1134447.png";
        return $this;
    }

    public function isCheese(): self
    {
        $this->name = 'fromage';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "https://cdn-icons-png.flaticon.com/128/3753/3753482.png";
        return $this;
    }

    public function isBakery(): self
    {
        $this->name = 'boulangerie';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= "https://cdn-icons-png.flaticon.com/128/3081/3081918.png";
        return $this;
    }
}
