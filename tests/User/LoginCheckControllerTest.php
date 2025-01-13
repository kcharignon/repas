<?php

namespace Repas\Tests\User;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LoginCheckControllerTest extends WebTestCase
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
