<?php

namespace Repas\User\Infrastructure\Catalog;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\User\Domain\Model\User as DomainUser;
use Repas\User\Infrastructure\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le propriétaire du catalogue d'ingrédients (ADR 0028, amendement du 2026-10-20) : le compte protégé (`User::PROTECTED_EMAIL`), un seul,
 * jamais « tout administrateur ». Le port est dans `Shared`, le contexte `Repas` ne connaît ni ce compte ni sa constante.
 *
 * En développement seulement, `INGREDIENT_CATALOG_OWNER_EMAIL` désigne un autre compte (par exemple celui de démonstration, dans
 * `.env.local`). Elle est vide dans `.env` et n'est posée nulle part en production : le compte protégé y est le propriétaire.
 *
 * La lecture est faite une fois par requête (le premier appel), y compris quand le compte n'existe pas (`null`). Le service est vidé entre deux
 * requêtes (`kernel.reset`, `ResetInterface`) : en mode worker FrankenPHP, le même objet sert plusieurs requêtes.
 */
final class IngredientCatalogOwnerPostgreSQL implements IngredientCatalogOwner, ResetInterface
{
    private bool $resolved = false;
    private ?string $ownerId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(default::INGREDIENT_CATALOG_OWNER_EMAIL)%')]
        private readonly ?string $overrideEmail = null,
    ) {
    }

    public function ownerId(): ?string
    {
        if (!$this->resolved) {
            $this->ownerId = $this->read();
            $this->resolved = true;
        }

        return $this->ownerId;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->ownerId = null;
    }

    private function read(): ?string
    {
        $override = $this->overrideEmail === null ? '' : DomainUser::normalizeEmail($this->overrideEmail);
        $email = $override !== '' ? $override : DomainUser::normalizeEmail(DomainUser::PROTECTED_EMAIL);

        $row = $this->entityManager->createQueryBuilder()
            ->select('u.id AS id')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['id'] ?? null;
    }
}
