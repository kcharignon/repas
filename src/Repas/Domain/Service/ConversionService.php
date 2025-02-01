<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Interface\ConversionRepository;

class ConversionService
{

    public function __construct(
        private ConversionRepository $conversionRepository
    ) {
    }
}
