<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportedMarmitonRecipe;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeCommand;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * Le controller de l'import d'une recette Marmiton (ADR 0037) avec un bus de commandes factice : la commande construite, le statut, les en-têtes et
 * la forme des erreurs. Le parcours entier (vrais bus, vraie base, vrais droits et limiteurs) : `ImportMarmitonApiFlowTest`.
 */
class ImportMarmitonRecipeApiControllerTest extends QueryBusWebTestCase
{
    private const string URL = 'https://www.marmiton.org/recettes/recette_tarte_22082.aspx';

    public function testDispatchesTheImportForTheCurrentUserAndAnswers201WithTheLocationOfTheCopy(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $commandBus->willAnswer(ImportMarmitonRecipeCommand::class, static fn(ImportMarmitonRecipeCommand $command): ImportedMarmitonRecipe => new ImportedMarmitonRecipe($command->newRecipeId, true));
        $user = $this->savedUser();

        // When
        $this->post(['url' => self::URL], $user);

        // Then : l'utilisateur vient du jeton, le lien du corps, l'identifiant de la copie est neuf
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', (string) $this->client->getResponse()->getContent());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(ImportMarmitonRecipeCommand::class, $command);
        $this->assertSame([self::URL, $user->getId()], [$command->url, $command->userId]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $command->newRecipeId);
        $this->assertSame('/api/v1/recipes/' . $command->newRecipeId, $this->client->getResponse()->headers->get('Location'));
    }

    public function testAnswers200WithTheLocationOfTheExistingCopyWhenThePersonAlreadyHasIt(): void
    {
        // Given
        $this->mockCommandBus()->willReturn(ImportMarmitonRecipeCommand::class, new ImportedMarmitonRecipe('0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10', false));

        // When
        $this->post(['url' => self::URL], $this->savedUser());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('/api/v1/recipes/0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10', $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheUserOfTheBodyIsNeverTrusted(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $commandBus->willReturn(ImportMarmitonRecipeCommand::class, new ImportedMarmitonRecipe('copy', true));
        $user = $this->savedUser();

        // When : le corps tente de désigner un autre utilisateur et de choisir l'identifiant de la copie
        $this->post(['url' => self::URL, 'userId' => 'someone-else', 'newRecipeId' => 'forced', 'authorId' => 'someone-else'], $user);

        // Then
        $command = $commandBus->dispatched()[0];
        $this->assertSame($user->getId(), $command->userId);
        $this->assertNotSame('forced', $command->newRecipeId);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans lien' => [[]];
        yield 'lien nul' => [['url' => null]];
        yield 'lien qui est un nombre' => [['url' => 22082]];
        yield 'lien qui est une liste' => [['url' => [self::URL]]];
        yield 'lien de plus de 2 000 caractères' => [['url' => str_repeat('a', 2001)]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBodyWith422AndDispatchesNothing(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($body, $this->savedUser());

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('message', $this->jsonBody()['error']);
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/recipes/import/marmiton', content: json_encode(['url' => self::URL]), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'pas une adresse de recette' => [MarmitonImportException::urlInvalid(), 422, 'MARMITON_URL_INVALID'];
        yield 'recette introuvable' => [MarmitonImportException::recipeNotFound(), 422, 'MARMITON_RECIPE_NOT_FOUND'];
        yield 'recette illisible' => [MarmitonImportException::recipeUnreadable(), 422, 'MARMITON_RECIPE_UNREADABLE'];
        yield 'Marmiton indisponible' => [MarmitonImportException::unavailable(), 502, 'MARMITON_UNAVAILABLE'];
        yield 'plafond de recettes du compte' => [RecipeException::limitReached(), 422, 'RECIPE_LIMIT_REACHED'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testAnswersTheStatusAndTheMessageOfTheRefusal(\Throwable $refusal, int $status, string $message): void
    {
        // Given
        $this->mockCommandBus()->willThrow(ImportMarmitonRecipeCommand::class, $refusal);

        // When
        $this->post(['url' => self::URL], $this->savedUser());

        // Then : le format de l'ADR 0007
        $this->assertSame($status, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $message]], $this->jsonBody());
        $this->assertNull($this->client->getResponse()->headers->get('Location'));
    }

    public function testAnswers429WithRetryAfterWhenThePageLimitIsReached(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(ImportMarmitonRecipeCommand::class, RateLimitedException::after(MarmitonImportException::RATE_LIMITED, 1800));

        // When
        $this->post(['url' => self::URL], $this->savedUser());

        // Then
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->assertSame('1800', $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertSame(['error' => ['message' => 'MARMITON_IMPORT_RATE_LIMITED']], $this->jsonBody());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, \Repas\User\Domain\Model\User $user): void
    {
        $this->client->request('POST', '/api/v1/recipes/import/marmiton', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
