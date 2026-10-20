<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListMergeCandidates;


use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListMergeCandidatesRequestQuery
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $q = null,
    ) {
    }
}
