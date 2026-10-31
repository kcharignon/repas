<?php

namespace Repas\Tests\Helper\InMemoryRepository;

use Repas\Repas\Domain\Interface\RecipePhraseCandidates;
use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Double idiot du port : renvoie les recettes programmées telles quelles (aucun filtre par utilisateur) et enregistre ses appels. Que la lecture soit
 * la bonne sur la base se prouve dans `RecipePhraseCandidatesRepositoryTest`.
 */
class RecipePhraseCandidatesInMemoryRepository implements RecipePhraseCandidates
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

    public function forUser(string $userId): array
    {
        $this->calls[] = $userId;

        return $this->candidates;
    }
}
