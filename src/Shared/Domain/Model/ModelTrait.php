<?php

namespace Repas\Shared\Domain\Model;


use DateTimeInterface;
use http\Exception\InvalidArgumentException;
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

    /**
     * @template T of ModelInterface
     * @param array|T $value The value to load.
     * @param class-string<T> $class The class name of the model to load.
     * @return T The loaded model instance.
     */
    private static function loadModel(array|ModelInterface $value, string $class): ModelInterface
    {
        if (!is_subclass_of($class, ModelInterface::class)) {
            throw new InvalidArgumentException(sprintf("%s must implement %s", $class, ModelInterface::class));
        }

        if ($value instanceof $class) {
            return $value;
        } else {
            return $class::load($value);
        }
    }


    /**
     * @template T of DateTimeInterface
     * @param string|T $value The value to load.
     * @param class-string<T> $class The class name of the model to load.
     * @return T The loaded datetime instance.
     */
    private static function loadDateTime(array|DateTimeInterface $value, string $class): DateTimeInterface
    {
        if (!is_subclass_of($class, DateTimeInterface::class)) {
            throw new InvalidArgumentException(sprintf("%s must implement %s", $class, DateTimeInterface::class));
        }

        if ($value instanceof $class) {
            return $value;
        } else {
            return $class::createFromFormat(DATE_ATOM, $value);
        }
    }
}
