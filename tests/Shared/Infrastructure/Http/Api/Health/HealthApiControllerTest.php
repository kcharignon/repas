<?php

namespace Repas\Tests\Shared\Infrastructure\Http\Api\Health;


use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthApiControllerTest extends WebTestCase
{
    public function testHealthReturnsOk(): void
    {
        // Given
        $client = static::createClient();

        // When
        $client->request('GET', '/api/v1/health');

        // Then
        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertSame(['status' => 'ok'], json_decode($client->getResponse()->getContent(), true));
    }

    public function testUnknownApiRouteReturnsJsonError(): void
    {
        // Given
        $client = static::createClient();

        // When
        $client->request('GET', '/api/v1/inconnu');

        // Then
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(
            ['error' => ['message' => 'Not Found']],
            json_decode($client->getResponse()->getContent(), true)
        );
    }

    public function testCorsHeadersAreSentToAllowedOrigin(): void
    {
        // Given
        $client = static::createClient();

        // When
        $client->request('GET', '/api/v1/health', server: ['HTTP_ORIGIN' => 'http://localhost:5173']);

        // Then
        $this->assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function testCorsHeadersAreNotSentOnWebPages(): void
    {
        // Given
        $client = static::createClient();

        // When
        $client->request('GET', '/login', server: ['HTTP_ORIGIN' => 'http://localhost:5173']);

        // Then
        $this->assertResponseNotHasHeader('Access-Control-Allow-Origin');
    }
}
