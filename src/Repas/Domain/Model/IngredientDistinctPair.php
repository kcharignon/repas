<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * « Pas le même » (ADR 0028 décision h, ING-006) : l'utilisateur a dit que ces deux ingrédients à lui ne sont PAS un doublon. La paire
 * n'est jamais reproposée par les doublons possibles. Une paire n'a pas de sens : `(a, b)` et `(b, a)` sont la même ligne, les deux
 * slugs sont rangés dans l'ordre des octets (`strcmp`, la collation « C » de la table), le premier avant le second. Identifiants
 * seulement (ADR 0013). Préférence privée : effacée avec le compte (ADR 0021), et quand l'un des deux ingrédients disparaît.
 */
final class IngredientDistinctPair implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $userId,
        private string $firstSlug,
        private string $secondSlug,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @throws IngredientException les deux slugs sont les mêmes
     */
    public static function create(string $userId, string $slug, string $otherSlug, DateTimeImmutable $createdAt): self
    {
        if ($slug === $otherSlug) {
            throw IngredientException::cannotBeDistinctFromItself();
        }
        [$first, $second] = self::ordered($slug, $otherSlug);

        return new self($userId, $first, $second, $createdAt);
    }

    public static function load(array $datas): self
    {
        return new self(
            userId: $datas['user_id'],
            firstSlug: $datas['first_slug'],
            secondSlug: $datas['second_slug'],
            createdAt: $datas['created_at'],
        );
    }

    /**
     * Les deux slugs dans l'ordre de la table : le premier est strictement avant le second (`strcmp`, comme `COLLATE "C"`).
     *
     * @return array{0: string, 1: string}
     */
    public static function ordered(string $slug, string $otherSlug): array
    {
        return strcmp($slug, $otherSlug) <= 0 ? [$slug, $otherSlug] : [$otherSlug, $slug];
    }

    /**
     * Une clé qui ne dépend pas de l'ordre des deux slugs : de quoi chercher une paire dans un ensemble.
     */
    public static function keyOf(string $slug, string $otherSlug): string
    {
        return implode("\n", self::ordered($slug, $otherSlug));
    }

    public function getId(): string
    {
        return $this->userId . '|' . $this->firstSlug . '|' . $this->secondSlug;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getFirstSlug(): string
    {
        return $this->firstSlug;
    }

    public function getSecondSlug(): string
    {
        return $this->secondSlug;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function key(): string
    {
        return $this->firstSlug . "\n" . $this->secondSlug;
    }

    public function names(string $slug): bool
    {
        return $this->firstSlug === $slug || $this->secondSlug === $slug;
    }
}
