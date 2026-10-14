<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;

/**
 * Un identifiant inconnu donne une page 404 sur les routes Twig, y compris pour un administrateur : le voter le laisse
 * passer avant de chercher la ressource, la lecture lève alors une exception de domaine (vrais bus, vraie base).
 */
class UnknownIdOnTwigRoutesTest extends DatabaseTestCase
{
    public function testAnAdministratorGetsA404ForAnUnknownRecipe(): void
    {
        // Given
        $client = static::getContainer()->get('test.client');
        $admin = new UserBuilder()->isAdmin()->build();
        static::getContainer()->get(UserRepository::class)->save($admin);
        $client->loginUser($admin);

        // When
        $client->request('GET', '/recipe/00000000-0000-4000-8000-000000000000');

        // Then
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorGetsA404ForAnUnknownDepartment(): void
    {
        // Given
        $client = static::getContainer()->get('test.client');
        $admin = new UserBuilder()->isAdmin()->build();
        static::getContainer()->get(UserRepository::class)->save($admin);
        $client->loginUser($admin);

        // When
        $client->request('GET', '/admin/department/inconnu/update');

        // Then
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }
}
