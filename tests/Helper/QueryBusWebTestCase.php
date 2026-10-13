<?php

namespace Repas\Tests\Helper;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Socle des tests de controllers de lecture (Twig et API, ADR 0010) : le QueryBus est remplacé par un MockQueryBus
 * avant la première requête du client (le kernel redémarre entre deux requêtes). L'utilisateur est un vrai
 * utilisateur de la base de test (annulé à la fin du test), connecté par session (Twig) ou par JWT (API).
 */
abstract class QueryBusWebTestCase extends DatabaseTestCase
{
    protected KernelBrowser $client;
    protected MockQueryBus $queryBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queryBus = new MockQueryBus();
        static::getContainer()->set(QueryBusInterface::class, $this->queryBus);
        $this->client = static::getContainer()->get('test.client');
    }

    protected function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * Liste de courses enregistrée en base de test (annulée à la fin du test), pour les controllers dont le voter
     * ou la commande lisent le vrai repository.
     */
    protected function savedShoppingList(User $owner, ShoppingListStatus $status = ShoppingListStatus::ACTIVE): ShoppingList
    {
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->withStatus($status)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);

        return $shoppingList;
    }

    /**
     * En-tête `Authorization: Bearer …` pour `$client->request(server: …)`, sans passer par /api/login_check.
     *
     * @return array<string, string>
     */
    protected function bearerFor(User $user): array
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
