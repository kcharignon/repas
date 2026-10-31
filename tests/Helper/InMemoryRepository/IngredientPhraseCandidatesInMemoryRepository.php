<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\IngredientPhraseCandidates;
use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Double idiot du port : renvoie les candidats programmés tels quels (ni filtre par utilisateur, ni habitude calculée) et enregistre ses
 * appels. Que la lecture soit la bonne sur la base se prouve dans `SpokenIngredientCandidatesPostgreSQLLoaderTest`.
 */
class IngredientPhraseCandidatesInMemoryRepository implements IngredientPhraseCandidates
{
    /** @var list<string> les utilisateurs demandés, dans l'ordre */
    public array $calls = [];

    /**
     * @param list<SpokenCandidate> $candidates
     */
    public function __construct(
        private array $candidates = [],
    ) {
    }

    public function add(SpokenCandidate $candidate): void
    {
        $this->candidates[] = $candidate;
    }

    /**
     * @return list<SpokenCandidate>
     */
    public function all(): array
    {
        return $this->candidates;
    }

    public function forUser(string $userId): array
    {
        $this->calls[] = $userId;

        return $this->candidates;
    }
}
