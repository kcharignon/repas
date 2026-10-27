<?php

namespace Repas\Tests\Shared\Infrastructure\Http\Api;


use Nelmio\ApiDocBundle\ApiDocGenerator;
use OpenApi\Annotations\OpenApi;
use Repas\Tests\Helper\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * `GET /api/doc.json` est public : le contrat n'est généré qu'une fois, puis relu dans le pool (config/packages/nelmio_api_doc.yaml ;
 * en production, un pool vidé à chaque démarrage du conteneur). En test le pool est en mémoire (`test.api_doc_pool`), conservé tant que le
 * noyau de test vit. Le générateur garde aussi le contrat en mémoire tant que son processus vit : `reset()` simule un autre processus
 * (un autre fil de FrankenPHP, un redémarrage).
 */
class ApiDocumentationCacheTest extends DatabaseTestCase
{
    private const string ITEM = 'openapi_doc.default';

    private KernelBrowser $client;
    private ArrayAdapter $pool;
    private ApiDocGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->pool = self::getContainer()->get('test.api_doc_pool');
        $this->generator = self::getContainer()->get('nelmio_api_doc.generator.default');
    }

    public function testTheContractIsGeneratedOnceThenReadFromThePoolByAnotherProcess(): void
    {
        // Given : rien dans le pool
        $this->assertFalse($this->pool->getItem(self::ITEM)->isHit());

        // When : une première lecture génère le contrat et le garde dans le pool
        $this->client->request('GET', '/api/doc.json');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->pool->getItem(self::ITEM)->isHit());

        // And : la copie gardée est modifiée, pour distinguer une relecture d'une nouvelle génération
        $item = $this->pool->getItem(self::ITEM);
        $cached = $item->get();
        $this->assertInstanceOf(OpenApi::class, $cached);
        $cached->info->title = 'Contrat relu dans le pool';
        $this->pool->save($item->set($cached));

        // And : un autre processus (rien en mémoire) répond à une seconde lecture
        $this->generator->reset();
        $this->client->request('GET', '/api/doc.json');
        $second = $this->client->getResponse();

        // Then : elle vient du pool, pas d'une nouvelle génération
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('Contrat relu dans le pool', json_decode($second->getContent(), true, flags: JSON_THROW_ON_ERROR)['info']['title']);
    }

    public function testTheContractReadFromThePoolIsTheSameAsTheGeneratedOne(): void
    {
        // When
        $this->client->request('GET', '/api/doc.json');
        $generated = $this->client->getResponse()->getContent();
        $this->generator->reset();
        $this->client->request('GET', '/api/doc.json');
        $fromPool = $this->client->getResponse()->getContent();

        // Then : même contenu, rien ne se perd dans le pool
        $this->assertSame($generated, $fromPool);
        $this->assertArrayHasKey('/api/login_check', json_decode($fromPool, true, flags: JSON_THROW_ON_ERROR)['paths']);
    }
}
