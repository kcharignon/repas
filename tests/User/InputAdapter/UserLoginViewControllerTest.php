<?php

namespace Repas\Tests\User\InputAdapter;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserLoginViewControllerTest extends WebTestCase
{

    public function testLoginSuccess(): void
    {
        //Arrange
        $client = static::createClient();

        //Act
        $crawler = $client->request('GET', '/login');

        //Assert
        $this->assertResponseIsSuccessful();

    }
}
