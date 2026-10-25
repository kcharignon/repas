<?php

namespace Repas\Voice\Application\Query\ListVoiceLinks;


use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListVoiceLinksQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
