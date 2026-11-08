<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Model\ImageUrl;

/**
 * Le lien direct d'une image (ADR 0039) : seule la forme d'un lien https vers un nom de domaine public est acceptée. Le nom est ensuite résolu et
 * contrôlé par l'adaptateur HTTP (`HttpImageFetcherTest`) ; ce test garde la première barrière, qui ne résout rien.
 */
class ImageUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'un lien simple' => ['https://exemple.org/images/surgeles.png', 'exemple.org', '/images/surgeles.png'];
        yield 'majuscules dans le nom et le schéma' => ['HTTPS://Exemple.ORG/Images/A.PNG', 'exemple.org', '/Images/A.PNG'];
        yield 'espaces autour' => ["  https://exemple.org/a.png \n", 'exemple.org', '/a.png'];
        yield 'le port 443 écrit' => ['https://exemple.org:443/a.png', 'exemple.org', '/a.png'];
        yield 'une requête' => ['https://cdn.exemple.org/a.png?w=200&v=2', 'cdn.exemple.org', '/a.png?w=200&v=2'];
        yield 'sans chemin' => ['https://exemple.org', 'exemple.org', '/'];
        yield 'une requête sans chemin' => ['https://exemple.org?a=1', 'exemple.org', '/?a=1'];
        yield 'le fragment est écarté' => ['https://exemple.org/a.png#section', 'exemple.org', '/a.png'];
        yield 'un nom en xn-- (accentué)' => ['https://xn--caf-dma.example.org/a.png', 'xn--caf-dma.example.org', '/a.png'];
        yield 'un octet non ASCII du chemin est encodé' => ['https://exemple.org/café.png', 'exemple.org', '/caf%C3%A9.png'];
        yield 'plusieurs sous-domaines' => ['https://a.b.c.exemple.org/x', 'a.b.c.exemple.org', '/x'];
        yield 'un nom avec des tirets et des chiffres' => ['https://img-01.cdn2.exemple.org/x', 'img-01.cdn2.exemple.org', '/x'];
    }

    #[DataProvider('accepted')]
    public function testAHttpsLinkToAPublicNameIsAccepted(string $raw, string $host, string $target): void
    {
        // When
        $url = ImageUrl::parse($raw);

        // Then
        $this->assertSame([$host, $target], [$url->host, $url->target]);
        $this->assertSame('https://' . $host . $target, $url->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'vide' => [''];
        yield 'des espaces' => ['   '];
        yield 'pas un lien' => ['une image'];
        yield 'http' => ['http://exemple.org/a.png'];
        yield 'ftp' => ['ftp://exemple.org/a.png'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'data' => ['data:image/png;base64,iVBORw0KGgo='];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'sans schéma' => ['exemple.org/a.png'];
        yield 'schéma relatif' => ['//exemple.org/a.png'];
        yield 'une adresse IPv4' => ['https://93.184.216.34/a.png'];
        yield 'loopback' => ['https://127.0.0.1/a.png'];
        yield 'les métadonnées du nuage' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'une adresse écrite en entier' => ['https://2130706433/a.png'];
        yield 'une adresse écrite en hexadécimal' => ['https://0x7f.0.0.1/a.png'];
        yield 'une adresse écrite en octal' => ['https://0177.0.0.1/a.png'];
        yield 'une adresse abrégée' => ['https://127.1/a.png'];
        yield 'une adresse IPv6' => ['https://[::1]/a.png'];
        yield 'une adresse IPv6 publique' => ['https://[2606:4700::6810:84e5]/a.png'];
        yield 'localhost' => ['https://localhost/a.png'];
        yield 'un nom sans point (service du réseau Docker)' => ['https://database/a.png'];
        yield 'un nom sans point, php' => ['https://php/api/v1/health'];
        yield 'un nom qui finit par un point' => ['https://exemple.org./a.png'];
        yield 'un point en tête' => ['https://.exemple.org/a.png'];
        yield 'deux points de suite' => ['https://exemple..org/a.png'];
        yield 'un suffixe de réseau local : .local' => ['https://imprimante.local/a.png'];
        yield 'un suffixe de réseau local : .localhost' => ['https://app.localhost/a.png'];
        yield 'un suffixe de réseau local : .internal' => ['https://metadata.google.internal/a.png'];
        yield 'un suffixe de réseau local : .lan' => ['https://nas.lan/a.png'];
        yield 'un suffixe de réseau local : .home.arpa' => ['https://nas.home.arpa/a.png'];
        yield 'un nom qui se termine par un chiffre' => ['https://exemple.123/a.png'];
        yield 'un autre port' => ['https://exemple.org:8443/a.png'];
        yield 'le port 80' => ['https://exemple.org:80/a.png'];
        yield 'un port vide' => ['https://exemple.org:/a.png'];
        yield 'un port non numérique' => ['https://exemple.org:abc/a.png'];
        yield 'un nom d\'utilisateur' => ['https://admin@exemple.org/a.png'];
        yield 'un mot de passe' => ['https://admin:secret@exemple.org/a.png'];
        yield 'l\'astuce de l\'arobase' => ['https://exemple.org@169.254.169.254/a.png'];
        yield 'l\'astuce de l\'arobase avec le port' => ['https://exemple.org:443@evil.example/a.png'];
        yield 'une barre oblique inverse' => ['https://exemple.org\\@evil.example/a.png'];
        yield 'une barre oblique inverse dans le chemin' => ['https://exemple.org/a\\b.png'];
        yield 'un espace dans le lien' => ['https://exemple.org/une image.png'];
        yield 'un retour à la ligne dans le lien' => ["https://exemple.org/a\r\nHost: evil.example"];
        yield 'un octet nul' => ["https://exemple.org/a\0.png"];
        yield 'un nom avec une lettre hors ASCII' => ['https://café.example.org/a.png'];
        yield 'un nom avec un chiffre pleine chasse' => ["https://exemple.org\u{FF0F}a.png"];
        yield 'un nom en pourcent' => ['https://exemple%2eorg/a.png'];
        yield 'un tiret en tête d\'étiquette' => ['https://-exemple.org/a.png'];
        yield 'plus de 2 000 caractères' => ['https://exemple.org/' . str_repeat('a', 2000)];
        yield 'une étiquette de plus de 63 caractères' => ['https://' . str_repeat('a', 64) . '.org/a.png'];
        yield 'un nom de plus de 253 caractères' => ['https://' . str_repeat('abcdefghi.', 26) . 'org/a.png'];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(string $raw): void
    {
        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::URL_REFUSED);

        // When
        ImageUrl::parse($raw);
    }

    public function testRefusalIsA422WithoutAnythingOfTheLink(): void
    {
        // When
        try {
            ImageUrl::parse('https://admin:secret@exemple.org/mot-de-passe.png');
            $this->fail('Le lien aurait dû être refusé.');
        } catch (ImageException $exception) {
            // Then : le message est un code, il ne cite jamais le lien
            $this->assertSame([422, 'IMAGE_URL_REFUSED'], [$exception->getCode(), $exception->getMessage()]);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirections(): iterable
    {
        yield 'un lien complet' => ['https://autre.exemple.net/b.png?x=1', 'https://autre.exemple.net/b.png?x=1'];
        yield 'un lien sans schéma' => ['//cdn.exemple.net/b.png', 'https://cdn.exemple.net/b.png'];
        yield 'un chemin absolu sur le même nom' => ['/images/b.png', 'https://exemple.org/images/b.png'];
        yield 'un chemin absolu avec une requête' => ['/b.png?v=3', 'https://exemple.org/b.png?v=3'];
        yield 'des espaces autour' => ["  https://autre.exemple.net/b.png\n", 'https://autre.exemple.net/b.png'];
        yield 'une arobase dans le chemin ne change jamais le nom' => ['/@127.0.0.1/b.png', 'https://exemple.org/@127.0.0.1/b.png'];
    }

    #[DataProvider('redirections')]
    public function testARedirectionIsJudgedLikeAFirstLink(string $location, string $expected): void
    {
        // Given
        $from = ImageUrl::parse('https://exemple.org/a/a.png');

        // When / Then
        $this->assertSame($expected, $from->redirectedTo($location)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedRedirections(): iterable
    {
        yield 'vide' => [''];
        yield 'un chemin relatif' => ['b.png'];
        yield 'un chemin relatif remontant' => ['../b.png'];
        yield 'http' => ['http://autre.exemple.net/b.png'];
        yield 'une adresse IP' => ['https://127.0.0.1/b.png'];
        yield 'les métadonnées du nuage' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'localhost' => ['https://localhost/b.png'];
        yield 'un autre port' => ['https://autre.exemple.net:8443/b.png'];
        yield 'sans schéma, une adresse IP' => ['//127.0.0.1/b.png'];
        yield 'un autre schéma' => ['gopher://autre.exemple.net/b.png'];
        yield 'file' => ['file:///etc/passwd'];
    }

    #[DataProvider('refusedRedirections')]
    public function testARedirectionToARefusedLinkIsRefused(string $location): void
    {
        // Given
        $from = ImageUrl::parse('https://exemple.org/a/a.png');

        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::URL_REFUSED);

        // When
        $from->redirectedTo($location);
    }
}
