<?php

namespace Repas\Drive\Infrastructure\Http\Api\ForgetChoice;


use Repas\Drive\Domain\Model\Retailer;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ForgetChoiceRequestQuery
{
    public function __construct(
        public Retailer $retailer,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public string $storeId,
    ) {
    }
}
