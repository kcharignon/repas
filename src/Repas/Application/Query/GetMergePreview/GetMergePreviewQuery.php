<?php

namespace Repas\Repas\Application\Query\GetMergePreview;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetMergePreviewQuery implements QueryInterface
{
    /**
     * @param string $userId l'utilisateur du jeton : les deux ingrédients doivent être à lui, sans exception pour un administrateur
     * @param string $absorbedSlug l'ingrédient qui disparaîtrait
     * @param string $keptSlug l'ingrédient qui recevrait tout
     */
    public function __construct(
        public string $userId,
        public string $absorbedSlug,
        public string $keptSlug,
    ) {
    }
}
