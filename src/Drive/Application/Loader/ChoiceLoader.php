<?php

namespace Repas\Drive\Application\Loader;

use Repas\Drive\Application\Query\ListChoices\ChoiceDTO;
use Repas\Drive\Domain\Model\Retailer;

interface ChoiceLoader
{
    /**
     * Les préférences de l'utilisateur dans son magasin courant pour l'enseigne (celui de ses réglages), par slug
     * d'ingrédient, avec leurs produits acceptés classés par rang et leurs produits refusés. Sans réglage, la liste est vide.
     *
     * @return list<ChoiceDTO>
     */
    public function list(string $userId, Retailer $retailer): array;
}
