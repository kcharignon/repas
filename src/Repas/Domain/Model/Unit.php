<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Tool\StringTool;

final readonly class Unit
{
    private Conversion $conversions;

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

    public static function load(array $data): self
    {
        return new self(
            $data['slug'],
            $data['name'],
            $data['symbol'],
        );
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
