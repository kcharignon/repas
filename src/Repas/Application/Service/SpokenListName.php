<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;

/**
 * Le nom qu'Alexa donne à une liste de courses d'un TYPE (ADR 0036, LST-008) : celui de la liste (une liste ouverte à la demande est nommée comme son
 * type, l'utilisateur peut l'avoir renommée), à défaut celui de son type. La liste principale n'a pas de nom : Alexa dit « ta liste », et ce service
 * n'est jamais appelé pour elle.
 *
 * Aucune lecture quand la liste porte un nom, ce qui est le cas de toute liste ouverte à la demande.
 */
readonly class SpokenListName
{
    /** Le nom d'une liste de type que ni elle ni son type ne portent plus. */
    public const string FALLBACK = 'une autre liste';

    public function __construct(
        private ShoppingListTypeRepository $listTypeRepository,
    ) {
    }

    /**
     * @param ?string $listName   le nom de la liste, s'il en a un
     * @param string  $listTypeId le type de la liste
     */
    public function ofTyped(?string $listName, string $listTypeId): string
    {
        if ($listName !== null && trim($listName) !== '') {
            return $listName;
        }

        try {
            return $this->listTypeRepository->findOneById($listTypeId)->getName();
        } catch (ShoppingListTypeException) {
            return self::FALLBACK;
        }
    }
}
