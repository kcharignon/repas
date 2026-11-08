<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\StoreUploadedImage\StoreUploadedImageCommand;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Tests\Helper\Image\Images;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * `POST /api/v1/images` (ADR 0039), le contrôleur seul (le bus de commandes enregistre sans exécuter) : administrateur seul, le fichier est lu BORNÉ et
 * transmis tel quel, les refus de lecture (pas de fichier, trop gros) ne lancent aucune commande. Le parcours complet est dans `ImageUploadApiFlowTest`.
 */
class UploadImageApiControllerTest extends QueryBusWebTestCase
{
    private const string PATH = 'images/uploads/3f5a0d1c9b7e4a2f8c6d1e0b9a8f7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b.png';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testAnAdministratorUploadsAFileAndGetsItsPathAndLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(StoreUploadedImageCommand::class, self::PATH);
        $png = Images::png();

        // When
        $this->post($this->savedUser(admin: true), $this->file($png));

        // Then : 201, le chemin à mettre dans `image`, l'adresse du fichier ; la commande porte le contenu, jamais le nom ni le type annoncés
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['path' => self::PATH], $this->jsonBody());
        $this->assertSame('/' . self::PATH, $this->client->getResponse()->headers->get('Location'));
        $this->assertEquals([new StoreUploadedImageCommand($png)], $commandBus->dispatched());
    }

    public function testTheNameAndTheTypeOfTheClientAreNeverUsed(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(StoreUploadedImageCommand::class, self::PATH);
        $path = $this->tempFile('contenu');

        // When
        $this->post($this->savedUser(admin: true), new UploadedFile($path, '../../etc/passwd.php', 'text/x-php', null, true));

        // Then : seul le contenu est transmis
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new StoreUploadedImageCommand('contenu')], $commandBus->dispatched());
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), $this->file(Images::png()));

        // Then : le fichier n'est même pas lu
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/images', files: ['file' => $this->file(Images::png())]);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesARequestWithoutFile(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/images', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_FILE_MISSING']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAFileSentAsAPlainField(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : le champ `file` est un texte, pas un fichier
        $this->client->request('POST', '/api/v1/images', ['file' => '/etc/passwd'], [], $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_FILE_MISSING']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAFileOverThreeMebibytesWith413AndRunsNoCommand(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $path = $this->tempFile(str_repeat('a', ImageNormalizer::MAX_BYTES + 1));

        // When
        $this->post($this->savedUser(admin: true), new UploadedFile($path, 'grosse.png', 'image/png', null, true));

        // Then
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_TOO_LARGE']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnInterruptedUpload(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $path = $this->tempFile('abc');

        // When
        $this->post($this->savedUser(admin: true), new UploadedFile($path, 'a.png', 'image/png', UPLOAD_ERR_PARTIAL, true));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_FILE_MISSING']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnUploadBiggerThanPhpAllowsIsTooLarge(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $path = $this->tempFile('');

        // When : PHP a refusé le fichier (upload_max_filesize) et n'en garde que le code d'erreur
        $this->post($this->savedUser(admin: true), new UploadedFile($path, 'a.png', 'image/png', UPLOAD_ERR_INI_SIZE, true));

        // Then
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_TOO_LARGE']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('otherMethods')]
    public function testOnlyPostIsAllowed(string $method): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request($method, '/api/v1/images', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function post(User $user, UploadedFile $file): void
    {
        $this->client->request('POST', '/api/v1/images', files: ['file' => $file], server: $this->bearerFor($user));
    }

    private function file(string $content): UploadedFile
    {
        return new UploadedFile($this->tempFile($content), 'surgeles.png', 'image/png', null, true);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }
}
