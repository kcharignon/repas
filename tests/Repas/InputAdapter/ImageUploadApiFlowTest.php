<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageFetcher;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\Image\ImageInMemoryFetcher;
use Repas\Tests\Helper\Image\Images;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * L'envoi d'images de l'administrateur (ADR 0039) de bout en bout : les vrais bus, le vrai voter, le vrai stockage (dans un dossier jetable), les vrais
 * limiteurs, les vraies erreurs HTTP ; seul le téléchargement est un double (`ImageInMemoryFetcher` : aucun test automatique n'appelle le réseau). Le
 * chemin rendu se met tel quel dans le champ `image` d'un rayon ou d'un type de recette, dont le contrat ne change pas.
 */
class ImageUploadApiFlowTest extends DatabaseTestCase
{
    private const string URL = 'https://exemple.org/images/surgeles.png';

    private KernelBrowser $client;
    private ImageInMemoryFetcher $fetcher;
    private User $admin;
    private User $alice;
    private string $publicDirectory;
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        // Le double du téléchargement et l'état des limiteurs survivent aux requêtes : le noyau n'est pas redémarré entre deux appels
        $this->client->disableReboot();
        $this->fetcher = new ImageInMemoryFetcher();
        static::getContainer()->set(ImageFetcher::class, $this->fetcher);

        $this->publicDirectory = static::getContainer()->getParameter('repas.images.public_directory');
        new Filesystem()->remove($this->publicDirectory);

        $this->admin = $this->savedUser(admin: true);
        $this->alice = $this->savedUser();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->publicDirectory);
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // --- Envoyer un fichier ------------------------------------------------------------------------------------------------------------

    public function testAnAdministratorUploadsAPngAndPutsTheReturnedPathInADepartment(): void
    {
        // When : il envoie une image
        $path = $this->upload($this->admin, Images::png(1000, 500, Images::BLUE));

        // Then : un PNG de 512 px nommé par son empreinte, gardé sur le disque
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['path' => $path], $this->jsonBody());
        $this->assertMatchesRegularExpression('#^images/uploads/[0-9a-f]{64}\.png$#', $path);
        $this->assertSame('/' . $path, $this->client->getResponse()->headers->get('Location'));
        $this->assertFileExists($this->publicDirectory . '/' . $path);
        $stored = (string) file_get_contents($this->publicDirectory . '/' . $path);
        $this->assertSame([512, 256, IMAGETYPE_PNG], [getimagesizefromstring($stored)[0], getimagesizefromstring($stored)[1], getimagesizefromstring($stored)[2]]);
        $this->assertSame($path, 'images/uploads/' . hash('sha256', $stored) . '.png');

        // When : le contrat des rayons n'a pas changé, le chemin se met tel quel dans `image`
        $this->request('POST', '/api/v1/departments', $this->admin, ['name' => 'Surgelés', 'image' => $path]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->request('GET', (string) $this->client->getResponse()->headers->get('Location'), $this->admin);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame($path, $this->jsonBody()['image']);
    }

    public function testTheReturnedPathAlsoFitsAnImageOfARecipeType(): void
    {
        // Given
        $path = $this->upload($this->admin, Images::webp(64, 64, Images::GREEN));
        $this->assertStringEndsWith('.webp', $path);

        // When
        $this->request('POST', '/api/v1/recipe-types', $this->admin, ['name' => 'Apéritif', 'image' => $path, 'order' => 4]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->request('GET', (string) $this->client->getResponse()->headers->get('Location'), $this->admin);
        $this->assertSame($path, $this->jsonBody()['image']);
    }

    public function testASvgIsStoredSanitized(): void
    {
        // Given
        $svg = str_replace('<title>', '<script>alert("a-ne-pas-garder")</script><title>', Images::svg());

        // When
        $path = $this->upload($this->admin, $svg);

        // Then : un fichier .svg, jamais le texte d'origine
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertMatchesRegularExpression('#^images/uploads/[0-9a-f]{64}\.svg$#', $path);
        $stored = (string) file_get_contents($this->publicDirectory . '/' . $path);
        $this->assertStringContainsString('<circle', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('a-ne-pas-garder', $stored);
    }

    public function testTheSameImageGivesTheSamePathAndOnlyOneFile(): void
    {
        // When
        $first = $this->upload($this->admin, Images::png(64, 64));
        $second = $this->upload($this->admin, Images::png(64, 64));

        // Then
        $this->assertSame($first, $second);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $this->storedFiles());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'un JPEG' => [Images::jpeg(), 422, 'IMAGE_UNSUPPORTED'];
        yield 'un GIF' => [Images::gif(), 422, 'IMAGE_UNSUPPORTED'];
        yield 'un texte' => ['ceci n\'est pas une image', 422, 'IMAGE_NOT_AN_IMAGE'];
        yield 'une page web' => [Images::htmlPage(), 422, 'IMAGE_NOT_AN_IMAGE'];
        yield 'un fichier vide' => ['', 422, 'IMAGE_NOT_AN_IMAGE'];
        yield 'une bombe de décompression' => [Images::pngClaiming(60000, 60000), 413, 'IMAGE_TOO_LARGE'];
        yield 'plus de 3 Mio' => [str_repeat('a', 3 * 1024 * 1024 + 1), 413, 'IMAGE_TOO_LARGE'];
        yield 'un SVG avec une entité externe' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><title>&x;</title></svg>', 422, 'IMAGE_UNSUPPORTED'];
        yield 'un SVG dont il ne reste rien' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script></svg>', 422, 'IMAGE_UNSUPPORTED'];
    }

    #[DataProvider('refusedFiles')]
    public function testARefusedFileHasItsStatusAndCodeAndWritesNothing(string $content, int $status, string $message): void
    {
        // When
        $this->upload($this->admin, $content);

        // Then
        $this->assertSame($status, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $message]], $this->jsonBody());
        $this->assertNull($this->client->getResponse()->headers->get('Location'));
        $this->assertSame([], $this->storedFiles());
    }

    public function testAnOrdinaryUserCannotUploadAndNothingIsWritten(): void
    {
        // When
        $this->upload($this->alice, Images::png());

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->storedFiles());
    }

    public function testNotLoggedInIs401(): void
    {
        // When
        $this->client->request('POST', '/api/v1/images', files: ['file' => $this->uploadedFile(Images::png())]);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->storedFiles());
    }

    public function testNoFileIs422(): void
    {
        // When
        $this->request('POST', '/api/v1/images', $this->admin);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_FILE_MISSING']], $this->jsonBody());
    }

    // --- Télécharger un lien -----------------------------------------------------------------------------------------------------------

    public function testAnAdministratorHasAnImageDownloadedFromALink(): void
    {
        // Given
        $png = Images::png(800, 400, Images::GREEN);
        $this->fetcher->willFetch(self::URL, $png);

        // When
        $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => self::URL]);

        // Then : le même chemin que si ce fichier avait été envoyé
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $path = $this->jsonBody()['path'];
        $this->assertSame(['path'], array_keys($this->jsonBody()));
        $this->assertSame('/' . $path, $this->client->getResponse()->headers->get('Location'));
        $this->assertSame([self::URL], $this->fetcher->calls());
        $this->assertSame($path, $this->upload($this->admin, $png));
        $this->assertCount(1, $this->storedFiles());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'http' => ['http://exemple.org/a.png'];
        yield 'une adresse IP' => ['https://127.0.0.1/a.png'];
        yield 'les métadonnées du nuage' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'localhost' => ['https://localhost/a.png'];
        yield 'un service du réseau' => ['https://database/a.png'];
        yield 'un nom d\'utilisateur' => ['https://admin@exemple.org/a.png'];
        yield 'un autre port' => ['https://exemple.org:8443/a.png'];
    }

    #[DataProvider('refusedLinks')]
    public function testARefusedLinkIs422WithItsCodeAndNothingIsCalled(string $url): void
    {
        // When
        $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => $url]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_URL_REFUSED']], $this->jsonBody());
        $this->assertSame(0, $this->fetcher->callCount());
        $this->assertSame([], $this->storedFiles());
    }

    public function testADownloadedPageThatIsNotAnImageIs422(): void
    {
        // Given
        $this->fetcher->willFetch(self::URL, Images::htmlPage());

        // When
        $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => self::URL]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_NOT_AN_IMAGE']], $this->jsonBody());
        $this->assertSame([], $this->storedFiles());
    }

    public function testASiteThatDoesNotAnswerIs502(): void
    {
        // Given
        $this->fetcher->willFail(self::URL, ImageException::downloadFailed());

        // When
        $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => self::URL]);

        // Then
        $this->assertSame(502, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_DOWNLOAD_FAILED']], $this->jsonBody());
    }

    public function testAnOrdinaryUserCannotHaveAnImageDownloaded(): void
    {
        // When
        $this->request('POST', '/api/v1/images/from-url', $this->alice, ['url' => self::URL]);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->fetcher->callCount());
    }

    public function testThirtyDownloadsAnHourThenA429WithRetryAfterButUploadsStillPass(): void
    {
        // Given : trente liens différents, tous bons
        for ($i = 1; $i <= 31; ++$i) {
            $this->fetcher->willFetch("https://exemple.org/image-$i.png", Images::png(10 + $i, 10));
        }
        for ($i = 1; $i <= 30; ++$i) {
            $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => "https://exemple.org/image-$i.png"]);
            $this->assertSame(201, $this->client->getResponse()->getStatusCode(), "Le téléchargement $i");
        }

        // When : un trente-et-unième
        $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => 'https://exemple.org/image-31.png']);

        // Then : 429, le délai d'attente, aucun appel de plus, rien d'écrit de plus
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_DOWNLOAD_RATE_LIMITED']], $this->jsonBody());
        $this->assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertSame(30, $this->fetcher->callCount());
        $this->assertCount(30, $this->storedFiles());

        // Un envoi de fichier ne compte pas dans ce plafond
        $this->upload($this->admin, Images::png(99, 99));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // Un autre administrateur a son propre compteur
        $otherAdmin = $this->savedUser(admin: true);
        $this->request('POST', '/api/v1/images/from-url', $otherAdmin, ['url' => 'https://exemple.org/image-31.png']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testARefusedLinkConsumesNothingOfTheLimit(): void
    {
        // Given : trente liens refusés d'emblée
        for ($i = 0; $i < 31; ++$i) {
            $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => 'https://127.0.0.1/a.png']);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        }
        $this->fetcher->willFetch(self::URL, Images::png());

        // When : un vrai lien ensuite
        $this->request('POST', '/api/v1/images/from-url', $this->admin, ['url' => self::URL]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    // --- Aides -------------------------------------------------------------------------------------------------------------------------

    /**
     * Envoie `$content` comme fichier et rend le chemin de la réponse (ou chaîne vide si elle n'en a pas).
     */
    private function upload(User $user, string $content): string
    {
        $this->client->request('POST', '/api/v1/images', files: ['file' => $this->uploadedFile($content)], server: $this->bearerFor($user));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($body) && isset($body['path']) ? $body['path'] : '';
    }

    private function uploadedFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, 'une-image.png', 'image/png', null, true);
    }

    /**
     * @return list<string> les fichiers gardés sur le disque
     */
    private function storedFiles(): array
    {
        $directory = $this->publicDirectory . '/images/uploads';

        return is_dir($directory) ? array_values(array_diff(scandir($directory) ?: [], ['.', '..'])) : [];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ($body === null ? [] : ['CONTENT_TYPE' => 'application/json']));
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function bearerFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(JWTTokenManagerInterface::class)->create($user)];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
