<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Double idiot : renvoie les candidats programmés tels quels (ni filtre par utilisateur, ni habitude calculée) et enregistre ses appels.
 */
class SpokenIngredientCandidatesInMemoryLoader implements SpokenIngredientCandidates
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param list<SpokenCandidate> $candidates
     */
    public function __construct(
        private readonly array $candidates = [],
    ) {
    }

    public function forUser(string $userId): array
    {
        $this->calls[] = $userId;

        return $this->candidates;
    }
}
