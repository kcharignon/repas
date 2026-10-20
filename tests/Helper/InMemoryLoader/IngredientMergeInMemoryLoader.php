<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\IngredientMergeLoader;
use Repas\Repas\Application\Query\GetMergePreview\MergePreviewDTO;
use Repas\Repas\Application\Query\ListMergeCandidates\MergeCandidateDTO;
use Repas\Repas\Domain\Exception\IngredientException;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri, ni verdict) et enregistre ses appels.
 */
class IngredientMergeInMemoryLoader implements IngredientMergeLoader
{
    /** @var list<array{string, string, string}> */
    public array $previewCalls = [];

    /** @var list<array{string, string, ?string}> */
    public array $candidatesCalls = [];

    /**
     * @param list<MergeCandidateDTO> $candidates
     */
    public function __construct(
        private readonly ?MergePreviewDTO $preview = null,
        private readonly array $candidates = [],
    ) {
    }

    public function preview(string $userId, string $absorbedSlug, string $keptSlug): MergePreviewDTO
    {
        $this->previewCalls[] = [$userId, $absorbedSlug, $keptSlug];

        return $this->preview ?? throw IngredientException::notFound($absorbedSlug);
    }

    public function candidates(string $userId, string $slug, ?string $search = null): array
    {
        $this->candidatesCalls[] = [$userId, $slug, $search];

        return $this->candidates;
    }
}
