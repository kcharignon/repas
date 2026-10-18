<?php

namespace Repas\Tests\Drive\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\Http\DriveProbeController;
use Repas\Tests\Helper\RateLimiterTimeTravel;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Le plafond de débit par utilisateur des routes du drive (DRV-615) de bout en bout : vraie sécurité JWT, vrais listeners, vrais
 * bus, vrais plafonds de `config/packages/rate_limiter.yaml` (60 écritures et 120 lectures par minute).
 *
 * Pour vider un compteur sans lancer 60 vraies écritures, on appelle les routes d'essai (`DriveProbeController`), qui consomment le
 * même compteur que les vraies routes ; on essaie ensuite chaque vraie route. L'état du limiteur est en mémoire (pool
 * `test.rate_limiter_pool`) et le noyau est gardé d'une requête à l'autre (`disableReboot`). Le temps passe sans dormir
 * (`RateLimiterTimeTravel`).
 */
class DriveRateLimitApiFlowTest extends DatabaseTestCase
{
    private const int WRITES_PER_MINUTE = 60;
    private const int READS_PER_MINUTE = 120;
    private const string STORE = '{"retailer":"leclerc","store":{"id":"123456","baseUrl":"https://exemple.leclercdrive.fr/magasin-123456-123456"}}';
    private const string LIST_ID = '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
    }

    public function testTheSixtyFirstWriteOfTheMinuteIsRefusedOnARealRouteAndNothingIsSaved(): void
    {
        // Given
        $token = $this->tokenOf($this->savedUser());

        // When : soixante enregistrements du magasin passent
        for ($i = 1; $i <= self::WRITES_PER_MINUTE; $i++) {
            $this->call('PUT', '/api/v1/drive/settings', $token, self::STORE);
            $this->assertSame(204, $this->statusCode(), "écriture $i");
        }
        $this->call('PUT', '/api/v1/drive/settings', $token, str_replace('123456', '654321', self::STORE));

        // Then : la soixante et unième est refusée avant d'entrer dans le controller
        $this->assertSame(429, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_RATE_LIMITED']], $this->jsonBody());
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $this->assertSame(['123456'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT store_id FROM drive_settings'));
    }

    public function testEveryWriteRouteSharesTheCounterAndIsRefusedOnceItIsFull(): void
    {
        // Given : le compteur d'écritures de l'utilisateur est plein
        $token = $this->tokenOf($this->savedUser());
        $this->fill(DriveProbeController::WRITE_LIMITED_PATH, 'PUT', self::WRITES_PER_MINUTE, $token);

        // When / Then : chaque vraie route d'écriture répond 429, même avec un corps invalide (le plafond passe avant la validation)
        $writes = [
            ['PUT', '/api/v1/drive/settings'],
            ['PUT', '/api/v1/drive/choices'],
            ['PUT', '/api/v1/drive/choices/farine'],
            ['DELETE', '/api/v1/drive/choices/farine?retailer=leclerc&storeId=123456'],
        ];
        foreach ($writes as [$method, $uri]) {
            $this->call($method, $uri, $token, '{}');
            $this->assertSame(429, $this->statusCode(), "$method $uri");
            $this->assertSame(['error' => ['message' => 'DRIVE_RATE_LIMITED']], $this->jsonBody(), "$method $uri");
            $this->assertNotNull($this->client->getResponse()->headers->get('Retry-After'), "$method $uri");
        }
    }

    public function testThePlanAndTheEstimateShareTheReadCounterAndAreRefusedOnceItIsFull(): void
    {
        // Given : le compteur de lectures coûteuses de l'utilisateur est plein
        $token = $this->tokenOf($this->savedUser());
        $this->fill(DriveProbeController::READ_LIMITED_PATH, 'GET', self::READS_PER_MINUTE, $token);

        // When / Then
        foreach (['/api/v1/drive/plans/' . self::LIST_ID, '/api/v1/drive/estimates/' . self::LIST_ID] as $uri) {
            $this->call('GET', $uri, $token);
            $this->assertSame(429, $this->statusCode(), $uri);
            $this->assertSame(['error' => ['message' => 'DRIVE_RATE_LIMITED']], $this->jsonBody(), $uri);
            $this->assertNotNull($this->client->getResponse()->headers->get('Retry-After'), $uri);
        }

        // Then : le compteur d'écritures, lui, est intact
        $this->call('PUT', '/api/v1/drive/settings', $token, self::STORE);
        $this->assertSame(204, $this->statusCode());
    }

    public function testTheReadsThatAreNotCostlyAreNeverLimited(): void
    {
        // Given : les deux compteurs sont pleins
        $token = $this->tokenOf($this->savedUser());
        $this->fill(DriveProbeController::WRITE_LIMITED_PATH, 'PUT', self::WRITES_PER_MINUTE, $token);
        $this->fill(DriveProbeController::READ_LIMITED_PATH, 'GET', self::READS_PER_MINUTE, $token);

        // When / Then : les réglages et la liste des préférences se lisent toujours
        $this->call('GET', '/api/v1/drive/settings', $token);
        $this->assertSame(200, $this->statusCode());
        $this->call('GET', '/api/v1/drive/choices?retailer=leclerc', $token);
        $this->assertSame(200, $this->statusCode());
    }

    public function testAnotherUserIsNotBlockedWhileTheFirstOneIs(): void
    {
        // Given : Alice a épuisé ses écritures
        $alice = $this->tokenOf($this->savedUser());
        $bob = $this->tokenOf($this->savedUser());
        $this->fill(DriveProbeController::WRITE_LIMITED_PATH, 'PUT', self::WRITES_PER_MINUTE, $alice);

        // When
        $this->call('PUT', '/api/v1/drive/settings', $bob, self::STORE);

        // Then : Bob (même adresse) passe, Alice est refusée
        $this->assertSame(204, $this->statusCode());
        $this->call('PUT', '/api/v1/drive/settings', $alice, self::STORE);
        $this->assertSame(429, $this->statusCode());
    }

    public function testTheRouteAnswersAgainOnceTheWindowHasPassed(): void
    {
        // Given : l'utilisateur a épuisé ses écritures et insiste (les refus ne comptent pas)
        $user = $this->savedUser();
        $token = $this->tokenOf($user);
        $this->fill(DriveProbeController::WRITE_LIMITED_PATH, 'PUT', self::WRITES_PER_MINUTE, $token);
        for ($i = 0; $i < 5; $i++) {
            $this->call('PUT', '/api/v1/drive/settings', $token, self::STORE);
            $this->assertSame(429, $this->statusCode());
        }

        // When : deux minutes passent
        new RateLimiterTimeTravel(static::getContainer()->get('test.rate_limiter_pool'))->advance('drive_write', 'user-' . $user->getId(), 121);

        // Then : le plafond est de nouveau entier
        for ($i = 1; $i <= self::WRITES_PER_MINUTE; $i++) {
            $this->call('PUT', '/api/v1/drive/settings', $token, self::STORE);
            $this->assertSame(204, $this->statusCode(), "écriture $i après la fenêtre");
        }
        $this->call('PUT', '/api/v1/drive/settings', $token, self::STORE);
        $this->assertSame(429, $this->statusCode());
    }

    public function testWithTheSwitchOffTheAnswerIs404AndNoTokenIsConsumed(): void
    {
        // Given
        $token = $this->tokenOf($this->savedUser());
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When : bien plus d'appels que le plafond
        for ($i = 0; $i < self::WRITES_PER_MINUTE + 10; $i++) {
            $this->call('PUT', DriveProbeController::WRITE_LIMITED_PATH, $token);

            // Then : toujours 404 DRIVE_DISABLED, jamais 429
            $this->assertSame(404, $this->statusCode());
            $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        }
        $this->call('PUT', '/api/v1/drive/settings', $token, self::STORE);
        $this->assertSame(404, $this->statusCode());
        $this->call('GET', '/api/v1/drive/plans/' . self::LIST_ID, $token);
        $this->assertSame(404, $this->statusCode());
        $this->assertSame([], static::getContainer()->get('test.rate_limiter_pool')->getValues(), 'Interrupteur coupé : aucun compteur ne doit être touché');
    }

    public function testAnAnonymousCallIsRefusedBySecurityAndConsumesNothing(): void
    {
        // When
        for ($i = 0; $i < self::WRITES_PER_MINUTE + 10; $i++) {
            $this->client->request('PUT', DriveProbeController::WRITE_LIMITED_PATH);

            // Then
            $this->assertSame(401, $this->statusCode());
        }
        $this->assertSame([], static::getContainer()->get('test.rate_limiter_pool')->getValues());
    }

    public function testTheLimiterAddsNoSqlRequest(): void
    {
        // Given : deux routes d'essai sans corps ni bus, l'une plafonnée, l'autre libre ; le même utilisateur
        $token = $this->tokenOf($this->savedUser());
        $counter = static::getContainer()->get(QueryCounter::class);
        $this->call('GET', DriveProbeController::FREE_PATH, $token);

        // When
        $counter->reset();
        $this->call('GET', DriveProbeController::FREE_PATH, $token);
        $withoutLimiter = $counter->statements();
        $counter->reset();
        $this->call('GET', DriveProbeController::READ_LIMITED_PATH, $token);
        $withLimiter = $counter->statements();

        // Then : mêmes requêtes (le chargement de l'utilisateur du jeton), le compteur ne passe pas par la base
        $this->assertSame(200, $this->statusCode());
        $this->assertSame($withoutLimiter, $withLimiter);
    }

    private function fill(string $path, string $method, int $count, string $token): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->call($method, $path, $token);
            $this->assertSame(200, $this->statusCode(), "appel d'essai $i");
        }
    }

    private function call(string $method, string $uri, string $token, ?string $content = null): void
    {
        $this->client->request($method, $uri, content: $content, server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tokenOf(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
