<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

final class Unit implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $slug,
        private string $name,
        private string $symbol,
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

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public static function create(
        string $name,
        string $symbol,
    ): self {
        $slug = StringTool::slugify($name);
        return new self(
            $slug,
            $name,
            $symbol,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            $datas['slug'],
            $datas['name'],
            $datas['symbol'],
        );
    }

    public function update(string $name, string $symbol): void
    {
        $this->name = $name;
        $this->symbol = $symbol;
    }
}
