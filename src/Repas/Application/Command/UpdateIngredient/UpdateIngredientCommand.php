<?php

namespace Repas\Repas\Application\Command\UpdateIngredient;


readonly class UpdateIngredientCommand
{
    /**
     * @param string    $userId l'utilisateur du jeton : le handler ne s'en sert que pour le marqueur « de base », que seul le propriétaire du
     *                          catalogue pose
     * @param bool|null $isBase le marqueur « de base » demandé (ADR 0028, amendement) ; `null` : on n'y touche pas (un ancien client qui ne
     *                          l'envoie pas ne remet donc jamais le marqueur à zéro)
     */
    public function __construct(
        public string  $slug,
        public string  $name,
        public ?string $image,
        public string  $departmentSlug,
        public string  $defaultCookingUnitSlug,
        public string  $defaultPurchaseUnitSlug,
        public ?float  $coefficient,
        public string  $userId,
        public ?bool   $isBase = null,
    ) {
    }

}
