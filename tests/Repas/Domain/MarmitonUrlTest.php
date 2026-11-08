<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Model\MarmitonUrl;

/**
 * L'adresse d'une recette Marmiton (ADR 0037 décision 12, MAR-001) : une seule forme est acceptée, et l'adresse appelée est reconstruite à
 * partir du nom et de l'identifiant, jamais reprise de ce que la personne a collé.
 */
class MarmitonUrlTest extends TestCase
{
    private const string CANONICAL = 'https://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx';

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedAddresses(): iterable
    {
        yield 'la forme exacte' => [self::CANONICAL];
        yield 'avec une requête' => [self::CANONICAL . '?utm_source=partage&utm_medium=lien'];
        yield 'avec un fragment' => [self::CANONICAL . '#ingredients'];
        yield 'avec une requête et un fragment' => [self::CANONICAL . '?a=1#b'];
        yield 'avec une requête qui contient une autre adresse' => [self::CANONICAL . '?next=https://evil.example/'];
        yield 'en http' => ['http://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx'];
        yield 'sans www' => ['https://marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx'];
        yield 'en http et sans www' => ['http://marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx'];
        yield 'en majuscules' => ['HTTPS://WWW.MARMITON.ORG/RECETTES/RECETTE_THE-TARTE-AU-CITRON-MERINGUEE_22082.ASPX'];
        yield 'avec une casse mêlée' => ['Https://Www.Marmiton.Org/Recettes/Recette_The-Tarte-Au-Citron-Meringuee_22082.aspx'];
        yield 'entouré d\'espaces et de retours à la ligne' => ["  \t" . self::CANONICAL . " \r\n"];
    }

    #[DataProvider('acceptedAddresses')]
    public function testAnAcceptedAddressGivesTheCanonicalOne(string $raw): void
    {
        // When
        $url = MarmitonUrl::parse($raw);

        // Then
        $this->assertSame('22082', $url->id);
        $this->assertSame('the-tarte-au-citron-meringuee', $url->slug);
        $this->assertSame(self::CANONICAL, $url->canonical());
        $this->assertSame(self::CANONICAL, $url->fetchUrl());
    }

    public function testNothingOfTheReceivedAddressSurvivesButTheNameAndTheIdentifier(): void
    {
        // Given
        $raw = 'http://MARMITON.org/recettes/recette_x-y_7.aspx?redirect=https://evil.example/&token=secret#fragment';

        // When
        $url = MarmitonUrl::parse($raw);

        // Then
        $this->assertSame('https://www.marmiton.org/recettes/recette_x-y_7.aspx', $url->fetchUrl());
        $this->assertStringNotContainsString('evil', $url->fetchUrl());
        $this->assertStringNotContainsString('secret', $url->fetchUrl());
    }

    public function testTheLongestPermittedNameAndIdentifierAreAccepted(): void
    {
        // Given
        $slug = str_repeat('a', 150);

        // When
        $url = MarmitonUrl::parse(sprintf('https://www.marmiton.org/recettes/recette_%s_%s.aspx', $slug, '999999999999'));

        // Then
        $this->assertSame($slug, $url->slug);
        $this->assertSame('999999999999', $url->id);
    }

    public function testTheLongestPermittedAddressIsAcceptedAndOneCharacterMoreIsNot(): void
    {
        // Given
        $base = self::CANONICAL . '?';

        // When
        $accepted = MarmitonUrl::parse($base . str_repeat('a', MarmitonUrl::MAX_LENGTH - strlen($base)));

        // Then
        $this->assertSame('22082', $accepted->id);
        $this->assertInvalid($base . str_repeat('a', MarmitonUrl::MAX_LENGTH - strlen($base) + 1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedAddresses(): iterable
    {
        // Un autre hôte
        yield 'marmiton.org.evil.com' => ['https://marmiton.org.evil.com/recettes/recette_x_22082.aspx'];
        yield 'www.marmiton.org.evil.com' => ['https://www.marmiton.org.evil.com/recettes/recette_x_22082.aspx'];
        yield 'evilmarmiton.org' => ['https://evilmarmiton.org/recettes/recette_x_22082.aspx'];
        yield 'www.evilmarmiton.org' => ['https://www.evilmarmiton.org/recettes/recette_x_22082.aspx'];
        yield 'm.marmiton.org (un sous-domaine)' => ['https://m.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'un sous-domaine de www' => ['https://a.www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'user@www.marmiton.org' => ['https://user@www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'user:mot@www.marmiton.org' => ['https://user:mot@www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'www.marmiton.org:8080' => ['https://www.marmiton.org:8080/recettes/recette_x_22082.aspx'];
        yield 'www.marmiton.org:443' => ['https://www.marmiton.org:443/recettes/recette_x_22082.aspx'];
        yield 'www.marmiton.org@evil.com' => ['https://www.marmiton.org@evil.com/recettes/recette_x_22082.aspx'];
        yield 'www.marmiton.org\@evil.com' => ['https://www.marmiton.org\\@evil.com/recettes/recette_x_22082.aspx'];
        yield 'www.marmiton.org suivi d\'un point' => ['https://www.marmiton.org./recettes/recette_x_22082.aspx'];
        yield 'une adresse IPv4' => ['https://93.184.216.34/recettes/recette_x_22082.aspx'];
        yield 'une adresse IPv4 en décimal' => ['https://1572395042/recettes/recette_x_22082.aspx'];
        yield 'une adresse IPv6' => ['https://[::1]/recettes/recette_x_22082.aspx'];
        yield 'localhost' => ['https://localhost/recettes/recette_x_22082.aspx'];
        yield 'localhost avec port' => ['http://localhost:8080/recettes/recette_x_22082.aspx'];
        yield 'l\'adresse de métadonnées du nuage' => ['http://169.254.169.254/recettes/recette_x_22082.aspx'];
        yield 'l\'hôte Unicode (i sans point)' => ["https://www.marmıton.org/recettes/recette_x_22082.aspx"];
        yield 'l\'hôte en IDN' => ['https://www.xn--marmiton-6xa.org/recettes/recette_x_22082.aspx'];
        yield 'le signe Kelvin' => ["https://www.\u{212A}marmiton.org/recettes/recette_x_22082.aspx"];
        yield 'l\'hôte seul' => ['https://www.marmiton.org'];
        yield 'l\'hôte avec une barre' => ['https://www.marmiton.org/'];

        // Un autre schéma
        yield 'ftp' => ['ftp://www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'javascript' => ['javascript:alert(1)//www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'file avec l\'hôte' => ['file://www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'sans schéma, avec deux barres' => ['//www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'sans schéma ni barres' => ['www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'schéma https mal écrit' => ['https:/www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'schéma avec un préfixe' => ['xhttps://www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'deux adresses collées' => [self::CANONICAL . ' https://evil.example/'];
        yield 'une adresse après la première, sans espace' => [self::CANONICAL . 'https://evil.example/'];

        // Un autre chemin
        yield 'la recherche' => ['https://www.marmiton.org/recettes/recherche.aspx?aqt=tarte'];
        yield 'sans identifiant' => ['https://www.marmiton.org/recettes/recette_x.aspx'];
        yield 'identifiant non numérique' => ['https://www.marmiton.org/recettes/recette_x_abc.aspx'];
        yield 'identifiant mêlé de lettres' => ['https://www.marmiton.org/recettes/recette_x_22a82.aspx'];
        yield 'sans nom' => ['https://www.marmiton.org/recettes/recette__22082.aspx'];
        yield 'sans extension' => ['https://www.marmiton.org/recettes/recette_x_22082'];
        yield 'une autre extension' => ['https://www.marmiton.org/recettes/recette_x_22082.html'];
        yield 'une extension qui se prolonge' => ['https://www.marmiton.org/recettes/recette_x_22082.aspx.evil'];
        yield 'un chemin qui se prolonge' => ['https://www.marmiton.org/recettes/recette_x_22082.aspx/autre'];
        yield 'un autre dossier' => ['https://www.marmiton.org/membre/recette_x_22082.aspx'];
        yield 'un dossier devant' => ['https://www.marmiton.org/a/recettes/recette_x_22082.aspx'];
        yield 'le chemin sans barre devant' => ['https://www.marmiton.orgrecettes/recette_x_22082.aspx'];
        yield 'un nom avec un souligné' => ['https://www.marmiton.org/recettes/recette_a_b_22082.aspx'];
        yield 'un nom avec une majuscule accentuée' => ['https://www.marmiton.org/recettes/recette_tarte-à-la-crème_22082.aspx'];
        yield 'un nom avec un point' => ['https://www.marmiton.org/recettes/recette_a.b_22082.aspx'];
        yield 'un nom avec une barre' => ['https://www.marmiton.org/recettes/recette_a/b_22082.aspx'];
        yield 'un nom de 151 caractères' => ['https://www.marmiton.org/recettes/recette_' . str_repeat('a', 151) . '_22082.aspx'];
        yield 'des points de remontée' => ['https://www.marmiton.org/recettes/../recettes/recette_x_22082.aspx'];
        yield 'seulement des points de remontée' => ['https://www.marmiton.org/recettes/recette_..._22082.aspx'];
        yield '.. seul' => ['..'];
        yield '../ devant' => ['../recettes/recette_x_22082.aspx'];
        yield '%2e%2e encodé' => ['https://www.marmiton.org/recettes/%2e%2e/recettes/recette_x_22082.aspx'];
        yield 'le nom encodé' => ['https://www.marmiton.org/recettes/recette_%78_22082.aspx'];
        yield 'le point de l\'extension encodé' => ['https://www.marmiton.org/recettes/recette_x_22082%2easpx'];
        yield 'un point-virgule dans le chemin' => ['https://www.marmiton.org/recettes/recette_x_22082.aspx;jsessionid=1/../'];

        // Des caractères qui n'ont rien à faire dans une adresse
        yield 'un retour à la ligne au milieu' => ["https://www.marmiton.org/recettes/recette_x_22082.aspx\nhttp://evil.example/"];
        yield 'un retour à la ligne dans l\'hôte' => ["https://www.marmiton.org\n.evil.com/recettes/recette_x_22082.aspx"];
        yield 'un retour chariot au milieu' => ["https://www.marmiton.org/recettes/recette_x_22082.aspx\rX-Injected: 1"];
        yield 'un octet nul au milieu' => ["https://www.marmiton.org/recettes/recette_x_22082.aspx\0.evil"];
        yield 'un octet nul à la fin' => ["https://www.marmiton.org/recettes/recette_x_22082.aspx\0"];
        yield 'un octet nul dans l\'hôte' => ["https://www.marmiton.org\0.evil.com/recettes/recette_x_22082.aspx"];
        yield 'une espace dans le chemin' => ['https://www.marmiton.org/recettes/recette_x y_22082.aspx'];
        yield 'une tabulation dans le chemin' => ["https://www.marmiton.org/recettes/recette_x\ty_22082.aspx"];
        yield 'un caractère de contrôle en requête' => ["https://www.marmiton.org/recettes/recette_x_22082.aspx?a=\x07"];
        yield 'une lettre accentuée en requête' => [self::CANONICAL . '?q=crème'];
        yield 'des octets invalides' => ["https://www.marmiton.org/recettes/recette_x_22082.aspx?\xff\xfe"];

        // Un identifiant hors des bornes
        yield 'identifiant de 13 chiffres' => ['https://www.marmiton.org/recettes/recette_x_1234567890123.aspx'];
        yield 'identifiant de zéro' => ['https://www.marmiton.org/recettes/recette_x_0.aspx'];
        yield 'identifiant qui commence par zéro' => ['https://www.marmiton.org/recettes/recette_x_022082.aspx'];
        yield 'identifiant négatif' => ['https://www.marmiton.org/recettes/recette_x_-22082.aspx'];
        yield 'identifiant décimal' => ['https://www.marmiton.org/recettes/recette_x_220.82.aspx'];
        yield 'identifiant en chiffres non latins' => ["https://www.marmiton.org/recettes/recette_x_\u{0662}\u{0662}.aspx"];

        // Du texte qui n'est pas une adresse
        yield 'le texte vide' => [''];
        yield 'des espaces' => ['   '];
        yield 'un mot' => ['marmiton'];
        yield 'un texte de 5 000 caractères' => [str_repeat('a', 5000)];
        yield 'une adresse de 5 000 caractères' => [self::CANONICAL . '?' . str_repeat('a', 5000)];
    }

    #[DataProvider('refusedAddresses')]
    public function testARefusedAddressIsInvalid(string $raw): void
    {
        $this->assertInvalid($raw);
    }

    public function testTheRefusalCarriesTheNamedMessageAndATerminalStatus(): void
    {
        // When / Then
        try {
            MarmitonUrl::parse('https://evil.example/recettes/recette_x_22082.aspx');
            $this->fail('Une adresse hors de Marmiton aurait dû être refusée.');
        } catch (MarmitonImportException $exception) {
            $this->assertSame('MARMITON_URL_INVALID', $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        }
    }

    private function assertInvalid(string $raw): void
    {
        try {
            MarmitonUrl::parse($raw);
            $this->fail('Cette adresse aurait dû être refusée : ' . json_encode(substr($raw, 0, 80), JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (MarmitonImportException $exception) {
            $this->assertSame(MarmitonImportException::URL_INVALID, $exception->getMessage());
        }
    }
}
