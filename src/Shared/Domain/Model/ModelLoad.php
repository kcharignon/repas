<?php

namespace Repas\Shared\Domain\Model;


use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;
use Repas\Shared\Domain\Tool\StringTool;

trait ModelLoad
{
    public static function load(array $datas): static
    {
        $instance = new static();
        $reflection = new ReflectionClass($instance);

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            $snakeCaseName = StringTool::camelCaseToLowerSnakeCase($name);

            if (array_key_exists($snakeCaseName, $datas)) {
                $dataValue = $datas[$snakeCaseName];

                // Vérifie si le type de la propriété implémente ModelInterface
                $type = $property->getType();
                if ($type && !$type->isBuiltin()) {
                    $typeName = $type->getName();

                    if (is_subclass_of($typeName, ModelInterface::class)) {
                        // Si l'attribut implémente ModelInterface, appel récursif de load
                        $property->setValue($instance, $typeName::load($dataValue));
                    } elseif (is_a($typeName, DateTimeImmutable::class, true)) {
                        // Si l'attribut est de type DateTimeImmutable
                        $property->setValue($instance, DateTimeImmutable::createFromFormat(DATE_ATOM, $dataValue));
                    } elseif (is_a($typeName, DateTime::class, true)) {
                        // Si l'attribut est de type DateTime
                        $property->setValue($instance, DateTime::createFromFormat(DATE_ATOM, $dataValue));
                    } else {
                        throw new InvalidArgumentException("Unsupported type {$typeName} for attribute {$name}.");
                    }
                } else {
                    // Si l'attribut est natif ou sans type spécifique
                    $property->setValue($instance, $dataValue);
                }
            }
        }

        return $instance;
    }
}
