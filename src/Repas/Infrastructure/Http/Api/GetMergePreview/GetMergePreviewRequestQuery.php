<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetMergePreview;


use Symfony\Component\Validator\Constraints as Assert;

final readonly class GetMergePreviewRequestQuery
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $into,
    ) {
    }
}
