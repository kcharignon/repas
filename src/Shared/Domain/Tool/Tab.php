<?php

namespace Repas\Shared\Domain\Tool;

use ArrayAccess;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Class Tab
 * @template T
 */
class Tab implements ArrayAccess, IteratorAggregate, Countable
{
    /**
     * @var T[]
     */
    private array $items = [];

    private ?string $type = null;
    /**
     * Tab constructor.
     *
     * @param T[] $elements
     * @throws InvalidArgumentException
     */
    public function __construct(array $elements, ?string $type = null)
    {
        if ($type !== null) {
            $this->type = $type;
        }

        foreach ($elements as $key => $element) {
            $this->add($element, $key);
        }
    }

    public static function newEmptyTyped(string $type): Tab
    {
        return new self([], $type);
    }

    /**
     * @param T[]|T $elements
     * @return Tab
     */
    public static function fromArray(...$elements): Tab
    {
        if (count($elements) === 1 && is_array($elements[0])) {
            $elements = $elements[0];
        }

        return new self($elements);
    }

    /**
     * Add an item to the Tab.
     *
     * @param T               $item
     * @param null|string|int $key
     * @throws InvalidArgumentException
     */
    public function add($item, null|string|int $key = null): void
    {
        $this->validateType($item);
        if (null !== $key) {
            $this->items[$key] = $item;
        } else {
            $this->items[] = $item;
        }
    }

    /**
     * Get all items in the Tab.
     *
     * @return T[]
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Map each item to a new value.
     *
     * @param callable(T): T $callback
     * @return Tab
     */
    public function map(callable $callback): Tab
    {
        $newItems = array_map($callback, $this->items);
        return self::fromArray($newItems);
    }

    /**
     * Filter items based on a callback.
     *
     * @param callable(T): bool|callable(T, int|string): bool|callable(int|string): bool $callback
     * @param int $mode
     * @return Tab<T>
     */
    public function filter(callable $callback, int $mode = 0): Tab
    {
        $filteredItems = array_filter($this->items, $callback, $mode);
        return new self($filteredItems, $this->type);
    }

    /**
     * Find the first item that matches the condition.
     *
     * @param callable(T, string|int): bool $callback
     * @return T|null
     */
    public function find(callable $callback)
    {
        return array_find($this->items, $callback);
    }

    /**
     * Find the key of the first item that matches the condition.
     *
     * @param callable(T): bool $callback
     * @return int|string|null
     */
    public function findKey(callable $callback): int|string|null
    {
        return array_find_key($this->items, $callback);
    }

    /**
     * Slice the Tab.
     *
     * @param int $offset
     * @param int|null $length
     * @param bool $preserveKeys
     * @return Tab<T>
     */
    public function slice(int $offset, ?int $length = null, bool $preserveKeys = false): Tab
    {
        return new self(array_slice($this->items, $offset, $length, $preserveKeys), $this->type);
    }

    public function implode(string $glue): string
    {
        return implode($glue, $this->items);
    }

    /**
     * @return array<T>
     */
    public function values(): array
    {
        return array_values($this->items);
    }

    /**
     * @return array<int|string>
     */
    public function keys(): array
    {
        return array_keys($this->items);
    }

    /**
     * Explode a string into a Tab.
     *
     * @param string $delimiter
     * @param string $string
     * @return Tab<string>
     */
    public static function explode(string $delimiter, string $string): Tab
    {
        return self::fromArray(explode($delimiter, $string));
    }

    /**
     * Get the number of items in the Tab.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if an offset exists.
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Get an item by its offset.
     *
     * @param mixed $offset
     * @return T|null
     */
    public function offsetGet($offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Set an item at a specific offset.
     *
     * @param mixed $offset
     * @param T $value
     * @throws InvalidArgumentException
     */
    public function offsetSet($offset, $value): void
    {
        $this->validateType($value);
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Unset an item at a specific offset.
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Get an iterator for the items.
     *
     * @return Traversable<T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Validate the type of an item.
     *
     * @param mixed $item
     * @throws InvalidArgumentException
     */
    private function validateType(mixed $item): void
    {
        if ($this->type === null) {
            $this->initializeType($item);
        }

        // Gestion des objets avec type spécifique (classe ou interface)
        if (class_exists($this->type) || interface_exists($this->type)) {
            if (!($item instanceof $this->type)) {
                throw new InvalidArgumentException(sprintf(
                    'Tab expected an instance of %s, got %s.',
                    $this->type,
                    is_object($item) ? get_class($item) : gettype($item)
                ));
            }
        } elseif ($this->type === 'object') {
            // Vérifie uniquement que c'est un objet
            if (!is_object($item)) {
                throw new InvalidArgumentException(sprintf(
                    'Tab expected an object, got %s.',
                    gettype($item)
                ));
            }
        } else {
            // Vérifie les types primitifs (string, int, etc.)
            if (gettype($item) !== $this->type) {
                throw new InvalidArgumentException(sprintf(
                    'Tab expected type %s, got %s.',
                    $this->type,
                    is_object($item) ? get_class($item) : gettype($item)
                ));
            }
        }
    }

    public function getType(): string
    {
        return $this->type ?? 'mixed';
    }

    public function merge(Tab ...$tabs): Tab
    {
        foreach ($tabs as $tab) {
            if ($tab->type !== $this->type) {
                throw new InvalidArgumentException(sprintf("Cannot merge Tab<%s>, with Tab<%s>.", $this->type, $tab->type));
            }
        }
        $arrayTabs = array_map(fn($tab) => $tab->items, $tabs);
        return static::fromArray(array_merge($this->items, ...$arrayTabs));
    }

    public function usort(callable $callback): void
    {
        usort($this->items, $callback);
    }

    private function initializeType(mixed $item): void
    {
        $this->type = is_object($item) ? get_class($item) : gettype($item);
    }

    /**
     * @return T|null
     */
    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    /**
     * @param int $flags :
     *  - `SORT_REGULAR` (0) : Compare items normally (don't change types).
     *  - `SORT_NUMERIC` (1) : Compare items numerically.
     *  - `SORT_STRING` (2) : Compare items as strings (default).
     *  - `SORT_LOCALE_STRING` (5) : Compare items as strings, based on the current locale.
     *
     * @return Tab<T>
     */
    public function unique(int $flags = SORT_STRING): Tab
    {
        return self::fromArray(array_unique($this->items, $flags));
    }

    /**
     * @param callable(T, mixed): mixed $callback
     * @param $initial
     * @return mixed
     */
    public function reduce(callable $callback, $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }
}

