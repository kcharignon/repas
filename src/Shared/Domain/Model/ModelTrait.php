<?php

namespace Repas\Shared\Domain\Model;


use DateTimeInterface;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;

trait ModelTrait
{
    public function toArray(): array
    {
        $result = [];

        foreach (get_object_vars($this) as $name => $value) {
            $snakeCaseName = StringTool::camelCaseToLowerSnakeCase($name);
            $result[$snakeCaseName] = $this->convert($value);
        }

        return $result;
    }

    private function convert(mixed $value): mixed
    {
        if ($value instanceof ModelInterface) {
            return $value->toArray();
        } elseif ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        } elseif ($value instanceof Tab) {
            return $value->map(fn($item) => $this->convert($item))->toArray();
        } else {
            return $value;
        }
    }

    public function isEqual(ModelInterface $model): bool
    {
        return $this::class === $model::class && $this->getId() === $model->getId();
    }
}
