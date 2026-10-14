<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;

class CreateIngredientViewControllerTest extends QueryBusWebTestCase
{
    public function testRedirectsAnAnonymousVisitorToTheLogin(): void
    {
        // When
        $this->client->request('GET', '/ingredient');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAnAnonymousSubmission(): void
    {
        // When
        $this->client->request('POST', '/ingredient', [
            'create_ingredient' => ['name' => 'zzz sel', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }
}
