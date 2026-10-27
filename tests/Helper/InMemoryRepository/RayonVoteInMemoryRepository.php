<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Drive\Domain\Interface\RayonVoteRepository;
use Repas\Drive\Domain\Model\RayonVote;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Enregistre les votes comme la vraie table : un par compte et par couple, les doublons et les couples déjà votés ne changent rien, et un
 * compte n'a pas plus de `RayonVote::MAX_PER_USER_AND_RETAILER` couples par enseigne. Aucune dépendance Doctrine.
 */
class RayonVoteInMemoryRepository implements RayonVoteRepository
{
    /** @var array<string, true> votes, par `compte|enseigne|rayon de Repas|rayon de l'enseigne` */
    private array $votes = [];

    /** @var list<int> nombre de votes reçus à chaque appel (un appel = une requête) */
    public array $recordSizes = [];

    /** @var list<string> comptes dont les votes ont été effacés */
    public array $deletedForUsers = [];

    public function record(string $userId, Retailer $retailer, array $votes): void
    {
        $this->recordSizes[] = count($votes);
        $room = RayonVote::MAX_PER_USER_AND_RETAILER - $this->countFor($userId, $retailer);
        foreach ($votes as $vote) {
            $key = $this->key($userId, $retailer, $vote->departmentSlug, $vote->rayonId);
            if (!isset($this->votes[$key]) && $room > 0) {
                $this->votes[$key] = true;
                $room--;
            }
        }
    }

    public function deleteByUser(string $userId): void
    {
        $this->deletedForUsers[] = $userId;
        $this->votes = array_filter($this->votes, static fn(string $key): bool => !str_starts_with($key, $userId . '|'), ARRAY_FILTER_USE_KEY);
    }

    public function hasVote(string $userId, Retailer $retailer, string $departmentSlug, int $rayonId): bool
    {
        return isset($this->votes[$this->key($userId, $retailer, $departmentSlug, $rayonId)]);
    }

    /**
     * Le nombre de comptes qui ont voté pour ce couple.
     */
    public function accountsFor(Retailer $retailer, string $departmentSlug, int $rayonId): int
    {
        $suffix = '|' . $retailer->value . '|' . $departmentSlug . '|' . $rayonId;

        return count(array_filter($this->votes, static fn(string $key): bool => str_ends_with($key, $suffix), ARRAY_FILTER_USE_KEY));
    }

    public function countFor(string $userId, Retailer $retailer): int
    {
        $prefix = $userId . '|' . $retailer->value . '|';

        return count(array_filter($this->votes, static fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @return list<string> tous les votes, par `compte|enseigne|rayon de Repas|rayon de l'enseigne`, triés
     */
    public function all(): array
    {
        $keys = array_keys($this->votes);
        sort($keys);

        return $keys;
    }

    private function key(string $userId, Retailer $retailer, string $departmentSlug, int $rayonId): string
    {
        return $userId . '|' . $retailer->value . '|' . $departmentSlug . '|' . $rayonId;
    }
}
