<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Infrastructure\Http\RepasWriteRateLimitListener;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RateLimiterTimeTravel;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Routing\RouterInterface;

/**
 * Le plafond d'écritures par utilisateur de l'API de `Repas` (300 par minute) de bout en bout : vraie authentification JWT, vrais listeners, vrais bus,
 * vrai plafond de `config/packages/rate_limiter.yaml`. L'état du limiteur est en mémoire (pool `test.rate_limiter_pool`) et le noyau est gardé
 * d'une requête à l'autre ; le temps passe sans dormir (`RateLimiterTimeTravel`).
 *
 * Pour vider le compteur sans lancer 300 vraies écritures, on le remplit d'un coup dans le même stockage que le limiteur ; pour compter une
 * écriture sans rien écrire, on coche une ligne qui n'existe pas : la requête est comptée avant d'entrer dans le controller, quelle que soit
 * sa réponse (404).
 */
class RepasWriteRateLimitApiFlowTest extends DatabaseTestCase
{
    private const int WRITES_PER_MINUTE = 300;
    private const string UNKNOWN_ROW = '/api/v1/shopping-list-rows/00000000-0000-4000-8000-000000000000/tick';

    private KernelBrowser $client;

    /** @var array<string, User> l'utilisateur de chaque jeton émis par ce test */
    private array $userOfToken = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
    }

    public function testTheThreeHundredAndFirstWriteOfTheMinuteIsRefusedBeforeItRunsAndNothingIsSaved(): void
    {
        // Given : le compteur de l'utilisateur est plein
        $user = $this->savedUser();
        $token = $this->tokenOf($user);
        $this->fill($token);

        // When : une écriture qui créerait une liste
        $this->call('PUT', '/api/v1/shopping-lists/active', $token);

        // Then : refusée avant d'entrer dans le controller
        $this->assertSame(429, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'WRITE_RATE_LIMITED']], $this->jsonBody());
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(61, $retryAfter);
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shopping_list'));
    }

    public function testTheOrdinaryUseOfAShoppingListNeverComesNearThePlafond(): void
    {
        // Given : quarante lignes cochées d'affilée, puis vingt « + » : une salve bien plus forte que l'usage réel
        $token = $this->tokenOf($this->savedUser());

        // When / Then : toutes passent la limite (le controller répond 404, la ligne n'existe pas)
        for ($i = 1; $i <= 120; $i++) {
            $this->call('POST', self::UNKNOWN_ROW, $token);
            $this->assertNotSame(429, $this->statusCode(), "écriture $i");
        }
    }

    public function testEveryKindOfWriteSharesTheCounterAndIsRefusedOnceItIsFull(): void
    {
        // Given
        $token = $this->tokenOf($this->savedUser());
        $this->fill($token);

        // When / Then : POST, PUT, PATCH et DELETE de Repas, même avec un corps invalide
        $writes = [
            ['POST', '/api/v1/recipes'],
            ['PUT', '/api/v1/recipes/0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10'],
            ['PATCH', '/api/v1/shopping-lists/0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10'],
            ['DELETE', '/api/v1/recipes/0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10'],
            ['POST', '/api/v1/ingredients'],
            ['POST', '/api/v1/shopping-lists/active/ingredients/farine/increase'],
        ];
        foreach ($writes as [$method, $uri]) {
            $this->call($method, $uri, $token, '{}');
            $this->assertSame(429, $this->statusCode(), "$method $uri");
            $this->assertSame(['error' => ['message' => 'WRITE_RATE_LIMITED']], $this->jsonBody(), "$method $uri");
            $this->assertNotNull($this->client->getResponse()->headers->get('Retry-After'), "$method $uri");
        }
    }

    public function testReadsAreNeverCountedNorRefused(): void
    {
        // Given : le compteur d'écritures est plein
        $token = $this->tokenOf($this->savedUser());
        $this->fill($token);

        // When / Then : les lectures répondent toujours
        foreach (['/api/v1/recipes/other-people', '/api/v1/shopping-lists', '/api/v1/ingredients/to-fix', '/api/v1/units'] as $uri) {
            $this->call('GET', $uri, $token);
            $this->assertSame(200, $this->statusCode(), $uri);
        }
    }

    public function testReadsConsumeNothingFromTheWriteCounter(): void
    {
        // Given : soixante lectures
        $token = $this->tokenOf($this->savedUser());
        for ($i = 0; $i < 60; $i++) {
            $this->call('GET', '/api/v1/units', $token);
        }

        // Then : aucun compteur n'est touché
        $this->assertSame([], static::getContainer()->get('test.rate_limiter_pool')->getValues());

        // When : une écriture
        $this->call('POST', self::UNKNOWN_ROW, $token);

        // Then : elle passe
        $this->assertNotSame(429, $this->statusCode());
    }

    public function testAnotherUserIsNotBlockedWhileTheFirstOneIs(): void
    {
        // Given : Alice a épuisé ses écritures
        $alice = $this->tokenOf($this->savedUser());
        $bob = $this->tokenOf($this->savedUser());
        $this->fill($alice);

        // When
        $this->call('PUT', '/api/v1/shopping-lists/active', $bob);

        // Then : Bob (même adresse) passe, Alice est refusée
        $this->assertSame(204, $this->statusCode());
        $this->call('PUT', '/api/v1/shopping-lists/active', $alice);
        $this->assertSame(429, $this->statusCode());
    }

    public function testTheRouteAnswersAgainOnceTheWindowHasPassedAndRefusalsAreNotCounted(): void
    {
        // Given : le compteur est plein et l'utilisateur insiste (les refus ne comptent pas)
        $user = $this->savedUser();
        $token = $this->tokenOf($user);
        $this->fill($token);
        for ($i = 0; $i < 5; $i++) {
            $this->call('PUT', '/api/v1/shopping-lists/active', $token);
            $this->assertSame(429, $this->statusCode());
        }

        // When : deux minutes passent
        new RateLimiterTimeTravel(static::getContainer()->get('test.rate_limiter_pool'))->advance('repas_write', 'user-' . $user->getId(), 121);

        // Then : une écriture passe, et le plafond est de nouveau entier : 299 de plus la laissent à 300, la suivante est refusée
        $this->call('PUT', '/api/v1/shopping-lists/active', $token);
        $this->assertSame(204, $this->statusCode());
        $this->consume($user, self::WRITES_PER_MINUTE - 1);
        $this->call('POST', self::UNKNOWN_ROW, $token);
        $this->assertSame(429, $this->statusCode());
    }

    public function testTheWritesOfTheAccountsAndOfTheOtherContextsKeepTheirOwnLimits(): void
    {
        // Given : le compteur d'écritures de Repas est plein
        $token = $this->tokenOf($this->savedUser());
        $this->fill($token);

        // When : une écriture du contexte des comptes
        $this->call('PUT', '/api/v1/me/settings', $token, '{}');

        // Then : elle n'est pas refusée par ce plafond (le corps vide est invalide, sans rapport)
        $this->assertNotSame('WRITE_RATE_LIMITED', $this->jsonBody()['error']['message'] ?? null);
        $this->assertNotSame(429, $this->statusCode());
    }

    public function testAnAnonymousWriteIsRefusedBySecurityAndConsumesNothing(): void
    {
        // When
        for ($i = 0; $i < 20; $i++) {
            $this->client->request('POST', self::UNKNOWN_ROW);

            // Then
            $this->assertSame(401, $this->statusCode());
        }
        $this->assertSame([], static::getContainer()->get('test.rate_limiter_pool')->getValues());
    }

    public function testTheLimiterAddsNoSqlRequest(): void
    {
        // Given : une écriture avant et après, sur le même utilisateur ; les lectures du jeton sont les mêmes
        $token = $this->tokenOf($this->savedUser());
        $counter = static::getContainer()->get(QueryCounter::class);
        $this->call('POST', self::UNKNOWN_ROW, $token);

        // When
        $counter->reset();
        $this->call('POST', self::UNKNOWN_ROW, $token);
        $withLimiter = $counter->statements();
        $counter->reset();
        $this->call('GET', '/api/v1/units', $token);

        // Then : le compteur ne passe pas par la base (le jeton et le controller lisent, le plafond n'écrit rien)
        $this->assertNotContains('INSERT', array_map(static fn(string $sql): string => strtoupper(strtok(ltrim($sql), ' ')), $withLimiter));
    }

    public function testEveryWriteRouteOfRepasIsLimitedAndNoReadRouteIs(): void
    {
        // Given
        self::bootKernel();

        // When
        $writes = $reads = 0;
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, RepasWriteRateLimitListener::CONTROLLER_NAMESPACE)) {
                continue;
            }
            $class = explode('::', $controller)[0];
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $limited = RepasWriteRateLimitListener::limits($class, $method);
                if (in_array($method, ['GET', 'HEAD'], true)) {
                    $this->assertFalse($limited, "$name ($method) est une lecture");
                    $reads++;
                } else {
                    $this->assertTrue($limited, "$name ($method) est une écriture sans plafond");
                    $writes++;
                }
            }
        }

        // Then : les routes de Repas sont toutes vues (une quarantaine d'écritures, autant de lectures)
        $this->assertGreaterThanOrEqual(35, $writes);
        $this->assertGreaterThanOrEqual(20, $reads);
    }

    public function testOnlyTheControllersOfRepasAreConcerned(): void
    {
        // When / Then
        $this->assertTrue(RepasWriteRateLimitListener::limits('Repas\\Repas\\Infrastructure\\Http\\Api\\CreateRecipe\\CreateRecipeApiController', 'POST'));
        $this->assertFalse(RepasWriteRateLimitListener::limits('Repas\\Repas\\Infrastructure\\Http\\Api\\ListUnits\\ListUnitsApiController', 'GET'));
        $this->assertFalse(RepasWriteRateLimitListener::limits('Repas\\Drive\\Infrastructure\\Http\\Api\\SaveDriveSettings\\SaveDriveSettingsApiController', 'PUT'));
        $this->assertFalse(RepasWriteRateLimitListener::limits('Repas\\User\\Infrastructure\\Http\\Api\\UpdateUserSettings\\UpdateUserSettingsApiController', 'PUT'));
        $this->assertFalse(RepasWriteRateLimitListener::limits('Repas\\Voice\\Infrastructure\\Http\\Api\\HandleAlexaRequest\\HandleAlexaRequestApiController', 'POST'));
        $this->assertFalse(RepasWriteRateLimitListener::limits(null, 'POST'));
    }

    /**
     * Vide le compteur de l'utilisateur du jeton : 300 écritures comptées d'un coup dans le même stockage que le limiteur (même nom, même clé).
     */
    private function fill(string $token): void
    {
        $this->consume($this->userOfToken[$token], self::WRITES_PER_MINUTE);
    }

    /**
     * Compte $hits écritures de l'utilisateur dans le même stockage que le limiteur (même nom, même clé), sans requête.
     */
    private function consume(User $user, int $hits): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'repas_write', 'policy' => 'sliding_window', 'limit' => self::WRITES_PER_MINUTE, 'interval' => '1 minute'],
            new CacheStorage(static::getContainer()->get('test.rate_limiter_pool')),
        );
        $this->assertTrue($factory->create('user-' . $user->getId())->consume($hits)->isAccepted());
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
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->userOfToken[$token] = $user;

        return $token;
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
