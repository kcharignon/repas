<?php

namespace Repas\Tests\Repas\Domain;


use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Service\SvgSanitizer;
use Repas\Tests\Helper\Image\Images;

/**
 * L'assainissement d'un SVG envoyé par l'administrateur (ADR 0039) : un SVG servi depuis notre origine peut exécuter du script, donc il n'est jamais
 * gardé tel quel. Liste blanche d'éléments et d'attributs, références internes seulement, DOCTYPE et entités refusés, plafonds de complexité, sortie
 * ré-écrite depuis l'arbre assaini et identique si on l'assainit de nouveau.
 */
class SvgSanitizerTest extends TestCase
{
    private const string NS = 'http://www.w3.org/2000/svg';
    private const string OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">';

    private SvgSanitizer $sanitizer;

    /** @var list<string> les ressources externes que libxml a demandé à charger (fichier, adresse, DTD), dans l'ordre */
    private array $externalLoads = [];
    /** @var resource un serveur TCP local : toute tentative de connexion de l'analyseur y serait reçue */
    private $listener;

    protected function setUp(): void
    {
        $this->sanitizer = new SvgSanitizer();

        // Le piège : libxml demande ce chargeur pour TOUT chargement externe (DTD, entité externe, fichier ou adresse) ; il note et refuse
        $this->externalLoads = [];
        libxml_set_external_entity_loader(function (?string $public, string $system): null {
            $this->externalLoads[] = $system;

            return null;
        });
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, (string) $errorMessage);
        $this->listener = $listener;
    }

    protected function tearDown(): void
    {
        libxml_set_external_entity_loader(null);
        fclose($this->listener);
    }

    /** L'adresse du serveur d'essai : une analyse qui la suivrait y laisserait une connexion. */
    private function trapAddress(): string
    {
        return (string) stream_socket_get_name($this->listener, false);
    }

    /**
     * Preuve qu'aucune ressource externe n'a été demandée : ni le chargeur de libxml (fichier, DTD, entité externe), ni le réseau (aucune connexion reçue).
     */
    private function assertNothingWasLoaded(): void
    {
        $this->assertSame([], $this->externalLoads, 'libxml a demandé une ressource externe.');
        $connection = @stream_socket_accept($this->listener, 0);
        $this->assertFalse($connection, 'Le serveur d\'essai a reçu une connexion.');
    }

    private function clean(string $svg): string
    {
        return $this->sanitizer->sanitize($svg)->bytes;
    }

    /** Un SVG de 10 × 10 qui contient `$inside` en plus d'un carré légitime (qui doit toujours être là). */
    private function wrap(string $inside): string
    {
        return self::OPEN . '<rect id="legitime" width="4" height="4" fill="#0a0"/>' . $inside . '</svg>';
    }

    private function assertRefused(string $svg, string $message): void
    {
        try {
            $this->sanitizer->sanitize($svg);
            $this->fail('Le SVG aurait dû être refusé avec ' . $message . '.');
        } catch (ImageException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------- un SVG propre passe et garde ses formes

    public function testACleanSvgKeepsItsShapesAndItsStructure(): void
    {
        // When
        $image = $this->sanitizer->sanitize(Images::svg());

        // Then
        $svg = $image->bytes;
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">', $svg);
        $this->assertStringContainsString('<title>Flocon</title>', $svg);
        $this->assertStringContainsString('<linearGradient id="degrade" x1="0" y1="0" x2="1" y2="1">', $svg);
        $this->assertStringContainsString('<stop offset="0" stop-color="#4fc3f7"/>', $svg);
        $this->assertStringContainsString('<rect width="24" height="24" rx="4" fill="url(#degrade)"/>', $svg);
        $this->assertStringContainsString('<circle cx="12" cy="12" r="5" fill="none" stroke="#fff" stroke-width="2"/>', $svg);
        $this->assertStringContainsString('<path d="M12 2v20M2 12h20" stroke="#fff" stroke-linecap="round" fill="none"/>', $svg);
        $this->assertSame([24, 24], [$image->width, $image->height]);
        $this->assertSame('svg', $image->extension);
        $this->assertSame(hash('sha256', $svg) . '.svg', $image->fileName());
    }

    public function testTheOutputIsWellFormedAndOnlyHasAllowedElements(): void
    {
        // When
        $svg = $this->clean(Images::svg());

        // Then : du XML bien formé, rien en dehors de la liste blanche, rien en dehors de l'espace de noms SVG
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($svg));
        $names = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            $this->assertInstanceOf(DOMElement::class, $element);
            $this->assertSame(self::NS, $element->namespaceURI);
            $names[$element->localName] = true;
        }
        $this->assertSame(['svg', 'title', 'defs', 'linearGradient', 'stop', 'rect', 'circle', 'path'], array_keys($names));
        $this->assertNull($document->doctype);
        $this->assertStringNotContainsString('<?xml', $svg);
    }

    public function testEveryAllowedElementIsKept(): void
    {
        // Given
        $svg = self::OPEN
            . '<title>t</title><desc>d</desc>'
            . '<defs><linearGradient id="l"><stop offset="0"/></linearGradient><radialGradient id="r" cx="5" cy="5" r="5"><stop offset="1"/></radialGradient>'
            . '<clipPath id="c"><rect width="5" height="5"/></clipPath><mask id="m"><circle r="3"/></mask><symbol id="s" viewBox="0 0 2 2"><path d="M0 0h2"/></symbol></defs>'
            . '<g transform="translate(1 1)" opacity="0.5"><ellipse cx="3" cy="3" rx="2" ry="1"/><line x1="0" y1="0" x2="5" y2="5"/>'
            . '<polyline points="0,0 1,1 2,0"/><polygon points="0,0 4,0 2,3"/></g><use href="#s"/></svg>';

        // When
        $clean = $this->clean($svg);

        // Then
        foreach (['title', 'desc', 'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'symbol', 'g', 'ellipse', 'line', 'polyline', 'polygon', 'use', 'rect', 'circle', 'path'] as $element) {
            $this->assertStringContainsString("<$element", $clean, $element);
        }
    }

    public function testTheSizeComesFromTheViewBoxOrFromTheWidthAndHeight(): void
    {
        // When / Then
        $this->assertSame([24, 12], $this->dimensions('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 12"><rect width="1" height="1"/></svg>'));
        $this->assertSame([24, 12], $this->dimensions('<svg xmlns="http://www.w3.org/2000/svg" viewBox="-5,-5,24.4,11.6"><rect width="1" height="1"/></svg>'));
        $this->assertSame([100, 50], $this->dimensions('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="50"><rect width="1" height="1"/></svg>'));
        $this->assertSame([100, 50], $this->dimensions('<svg xmlns="http://www.w3.org/2000/svg" width="100px" height="50.4px"><rect width="1" height="1"/></svg>'));
        $this->assertSame([24, 24], $this->dimensions('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="100%" height="100%"><rect width="1" height="1"/></svg>'));
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(string $svg): array
    {
        $image = $this->sanitizer->sanitize($svg);

        return [$image->width, $image->height];
    }

    // ---------------------------------------------------------------- ce qui doit disparaître

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function dangerous(): iterable
    {
        yield '<script>' => ['<script>alert(1)</script>', ['script', 'alert']];
        yield '<script> avec un lien' => ['<script xlink:href="http://evil.example/x.js"/>', ['script', 'evil.example']];
        yield 'onload sur la racine' => ['<g onload="alert(1)"><circle r="2"/></g>', ['onload', 'alert']];
        yield 'onclick sur une forme' => ['<circle r="2" onclick="alert(1)" onmouseover="alert(2)"/>', ['onclick', 'onmouseover', 'alert']];
        yield 'un gestionnaire dans l\'espace de noms SVG' => ['<rect width="1" height="1" onfocusin="alert(1)" onbegin="alert(2)"/>', ['onfocusin', 'onbegin', 'alert']];
        yield '<foreignObject>' => ['<foreignObject width="5" height="5"><div xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></div></foreignObject>', ['foreignObject', 'div', 'script', 'alert']];
        yield '<iframe>' => ['<iframe src="http://evil.example/"/>', ['iframe', 'evil.example']];
        yield '<image> avec une donnée' => ['<image href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" width="5" height="5"/>', ['<image', 'data:', 'base64']];
        yield '<image> avec une adresse' => ['<image xlink:href="http://evil.example/p.png" width="5" height="5"/>', ['<image', 'evil.example']];
        yield 'href vers une adresse distante' => ['<use href="http://evil.example/sprite.svg#x"/>', ['evil.example', 'sprite']];
        yield 'xlink:href vers une adresse distante' => ['<use xlink:href="http://evil.example/sprite.svg#x"/>', ['evil.example', 'sprite']];
        yield 'xlink:href javascript:' => ['<use xlink:href="javascript:alert(1)"/>', ['javascript', 'alert']];
        yield 'href javascript: en majuscules' => ['<use href="JaVaScRiPt:alert(1)"/>', ['javascript', 'alert']];
        yield 'href javascript: caché par des blancs' => ["<use href=\"java\tscript:alert(1)\"/>", ['alert']];
        yield 'href javascript: caché par une référence de caractère' => ['<use href="&#106;avascript:alert(1)"/>', ['alert', 'avascript']];
        yield 'href data:' => ['<use href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"/>', ['data:', 'alert']];
        yield 'href relatif' => ['<use href="autre.svg#x"/>', ['autre.svg']];
        yield 'un lien <a>' => ['<a href="http://evil.example/"><circle r="2"/></a>', ['<a', 'evil.example']];
        yield '<animate>' => ['<circle r="2"><animate attributeName="onclick" to="alert(1)" begin="0s"/></circle>', ['animate', 'alert']];
        yield '<set>' => ['<set attributeName="href" to="javascript:alert(1)"/>', ['<set', 'alert']];
        yield '<animateTransform>' => ['<animateTransform attributeName="transform" type="rotate" from="0" to="360" dur="1s"/>', ['animateTransform']];
        yield '<text> (hors de la liste blanche)' => ['<text x="1" y="1">bonjour</text>', ['<text', 'bonjour']];
        yield '<switch>' => ['<switch><circle r="2"/></switch>', ['<switch']];
        yield '<filter>' => ['<filter id="f"><feGaussianBlur stdDeviation="2"/></filter>', ['<filter', 'feGaussianBlur']];
        yield 'un élément XHTML' => ['<script xmlns="http://www.w3.org/1999/xhtml">alert(1)</script>', ['script', 'alert']];
        yield 'un élément préfixé d\'un autre espace de noms' => ['<h:script xmlns:h="http://www.w3.org/1999/xhtml">alert(1)</h:script>', ['script', 'alert']];
        yield 'un <script> préfixé par l\'espace de noms SVG' => ['<s:script xmlns:s="http://www.w3.org/2000/svg">alert(1)</s:script>', ['script', 'alert']];
        yield '<style> avec @import' => ['<style>@import url(http://evil.example/x.css); rect { fill: red }</style>', ['@import', 'evil.example', '<style']];
        yield '<style> avec un chargement distant' => ['<style>rect { fill: url(http://evil.example/x.svg#a) }</style>', ['evil.example', '<style']];
        yield '<style> avec @font-face' => ['<style>@font-face { font-family: x; src: url(data:font/woff;base64,AAAA) }</style>', ['@font-face', 'data:', '<style']];
        yield '<style> avec une échappement CSS' => ['<style>rect { fill: u\\72l(http://evil.example) }</style>', ['evil.example', '<style']];
        yield '<style> avec expression()' => ['<style>rect { width: expression(alert(1)) }</style>', ['expression', 'alert', '<style']];
        yield '<style> avec behavior' => ['<style>rect { behavior: url(x.htc) }</style>', ['behavior', '<style']];
        yield '<style> avec -moz-binding' => ['<style>rect { -moz-binding: url(http://evil.example/x.xml#b) }</style>', ['-moz-binding', 'evil.example', '<style']];
        yield '<style> avec un commentaire' => ['<style>/* ur */ rect { fill: red }</style>', ['<style']];
        yield 'attribut style avec une adresse' => ['<rect width="3" height="3" style="fill:url(http://evil.example/x)"/>', ['evil.example']];
        yield 'attribut style avec behavior' => ['<rect width="3" height="3" style="behavior:url(x.htc);fill:red"/>', ['behavior', 'x.htc']];
        yield 'attribut style avec expression' => ['<rect width="3" height="3" style="width:expression(alert(1))"/>', ['expression', 'alert']];
        yield 'attribut style avec une donnée' => ['<rect width="3" height="3" style="fill:url(data:image/svg+xml;base64,AAAA)"/>', ['data:', 'base64']];
        yield 'fill avec une adresse' => ['<rect width="3" height="3" fill="url(http://evil.example/x)"/>', ['evil.example']];
        yield 'fill avec une adresse entre guillemets' => ['<rect width="3" height="3" fill="url(\'http://evil.example/x\')"/>', ['evil.example']];
        yield 'fill avec une adresse après une référence' => ['<rect width="3" height="3" fill="url(#a) url(http://evil.example/x)"/>', ['evil.example']];
        yield 'fill avec javascript:' => ['<rect width="3" height="3" fill="javascript:alert(1)"/>', ['javascript', 'alert']];
        yield 'clip-path avec une adresse' => ['<rect width="3" height="3" clip-path="url(http://evil.example/x#c)"/>', ['evil.example']];
        yield 'une barre oblique inverse' => ['<rect width="3" height="3" fill="u\\72l(http://evil.example)"/>', ['evil.example']];
        yield 'un attribut inconnu' => ['<rect width="3" height="3" data-x="1" aria-label="a" tabindex="0" contenteditable="true"/>', ['data-x', 'aria-label', 'tabindex', 'contenteditable']];
        yield 'des attributs d\'éditeur (Inkscape)' => ['<rect width="3" height="3" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" inkscape:label="calque" xml:space="preserve"/>', ['inkscape', 'calque', 'xml:space']];
        yield 'des métadonnées RDF' => ['<metadata><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description about="x"/></rdf:RDF></metadata>', ['metadata', 'rdf', 'Description']];
        yield 'une instruction de traitement qui ne charge rien' => ['<?adobe-edition version="2"?><?xml-stylesheet type="text/css"?><?xml-stylesheet href="#interne"?>', ['adobe-edition', 'xml-stylesheet', 'interne']];
        yield 'un commentaire' => ['<!-- onload=alert(1) -->', ['<!--', 'onload']];
        yield 'une section CDATA hors d\'un style' => ['<g><![CDATA[<script>alert(1)</script>]]></g>', ['CDATA', 'alert']];
    }

    /**
     * @param list<string> $forbidden
     */
    #[DataProvider('dangerous')]
    public function testWhatIsDangerousOrUnknownIsRemovedAndTheRestIsKept(string $inside, array $forbidden): void
    {
        // When
        $clean = $this->clean($this->wrap($inside));

        // Then : le carré légitime est toujours là, pas une trace de ce qui est retiré
        $this->assertStringContainsString('<rect id="legitime" width="4" height="4" fill="#0a0"/>', $clean);
        foreach ($forbidden as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $clean, "« $fragment » devrait avoir disparu de : $clean");
        }

        // Et le résultat se relit comme du XML bien formé, sans élément ni attribut hors de la liste blanche
        $this->assertOnlyAllowedMarkup($clean);
    }

    public function testTheRootItselfIsCleanedToo(): void
    {
        // Given : les pièges sur la racine
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)" onclick="alert(2)" style="behavior:url(x.htc)" xlink:href="javascript:alert(3)"><rect width="1" height="1"/></svg>';

        // When
        $clean = $this->clean($svg);

        // Then
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>', $clean);
    }

    private function assertOnlyAllowedMarkup(string $svg): void
    {
        $allowedElements = ['svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'symbol', 'use', 'title', 'desc', 'style'];
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($svg), $svg);
        $this->assertNull($document->doctype);
        foreach ($document->getElementsByTagName('*') as $element) {
            $this->assertInstanceOf(DOMElement::class, $element);
            $this->assertContains($element->localName, $allowedElements);
            $this->assertSame(self::NS, $element->namespaceURI);
            foreach ($element->attributes as $attribute) {
                $this->assertStringStartsNotWith('on', $attribute->nodeName);
                $this->assertNull($attribute->namespaceURI, $attribute->nodeName);
                if ($attribute->nodeName === 'href') {
                    $this->assertMatchesRegularExpression('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', $attribute->value);
                }
                $this->assertDoesNotMatchRegularExpression('/javascript:|data:|\\\\/i', $attribute->value);
                $this->assertDoesNotMatchRegularExpression('/url\(\s*(?!#)/i', $attribute->value);
            }
        }
    }

    // ---------------------------------------------------------------- ce qui est gardé

    public function testInternalReferencesAreKept(): void
    {
        // Given
        $svg = self::OPEN . '<defs><linearGradient id="a"><stop offset="0"/></linearGradient><linearGradient id="b" xlink:href="#a"/><g id="forme"><circle r="2"/></g></defs>'
            . '<rect width="3" height="3" fill="url(#a)" stroke="url( #b )" clip-path="url(#a)" mask="url(#a)"/><use xlink:href="#forme"/></svg>';

        // When
        $clean = $this->clean($svg);

        // Then : `xlink:href` devient `href` (la sortie n'a pas de préfixe), les références internes restent
        $this->assertStringContainsString('<linearGradient id="b" href="#a"/>', $clean);
        $this->assertStringContainsString('<use href="#forme"/>', $clean);
        $this->assertStringContainsString('fill="url(#a)"', $clean);
        $this->assertStringContainsString('stroke="url( #b )"', $clean);
        $this->assertStringContainsString('clip-path="url(#a)"', $clean);
        $this->assertStringContainsString('mask="url(#a)"', $clean);
        $this->assertStringNotContainsString('xlink', $clean);
    }

    public function testAnAttributeStyleIsRebuiltFromItsSafeDeclarations(): void
    {
        // Given
        $svg = $this->wrap('<rect width="3" height="3" style=" FILL : #f00 ; stroke:url(#g);background:url(http://evil.example/x);stroke-width: 2 ;unknown:1;opacity:.5"/>');

        // When
        $clean = $this->clean($svg);

        // Then
        $this->assertStringContainsString('<rect width="3" height="3" style="fill:#f00;stroke:url(#g);stroke-width:2;opacity:.5"/>', $clean);
    }

    public function testASafeStyleElementIsKept(): void
    {
        // When
        $clean = $this->clean($this->wrap('<style>.a { fill: #f00 } rect > .b { stroke: url(#g) }</style>'));

        // Then : le texte est ré-écrit, les caractères spéciaux échappés
        $this->assertStringContainsString('<style>.a { fill: #f00 } rect &gt; .b { stroke: url(#g) }</style>', $clean);
    }

    public function testAStyleElementInACdataSectionIsKeptWithoutIt(): void
    {
        // When
        $clean = $this->clean($this->wrap('<style type="text/css"><![CDATA[.a { fill: #f00 }]]></style>'));

        // Then
        $this->assertStringContainsString('<style>.a { fill: #f00 }</style>', $clean);
        $this->assertStringNotContainsString('CDATA', $clean);
    }

    public function testTooManyStyleElementsAreDropped(): void
    {
        // Given
        $styles = str_repeat('<style>.a { fill: red }</style>', SvgSanitizer::MAX_STYLES + 3);

        // When
        $clean = $this->clean($this->wrap($styles));

        // Then
        $this->assertSame(SvgSanitizer::MAX_STYLES, substr_count($clean, '<style>'));
    }

    public function testAnOversizedStyleElementIsDropped(): void
    {
        // When
        $clean = $this->clean($this->wrap('<style>' . str_repeat('.a { fill: red } ', (int) (SvgSanitizer::MAX_STYLE_BYTES / 10)) . '</style>'));

        // Then
        $this->assertStringNotContainsString('<style', $clean);
    }

    public function testDuplicateIdsKeepOnlyTheFirst(): void
    {
        // When
        $clean = $this->clean($this->wrap('<circle id="legitime" r="2"/><circle id="autre" r="3"/>'));

        // Then
        $this->assertSame(1, substr_count($clean, 'id="legitime"'));
        $this->assertStringContainsString('<circle r="2"/>', $clean);
        $this->assertStringContainsString('<circle id="autre" r="3"/>', $clean);
    }

    public function testAnInvalidIdOrClassIsDropped(): void
    {
        // When
        $clean = $this->clean($this->wrap('<circle id="a b" class="bon mauvais&quot;x" r="2"/><circle id="1x" class="  a   b " r="3"/>'));

        // Then
        $this->assertStringContainsString('<circle r="2"/>', $clean);
        $this->assertStringContainsString('<circle class="a b" r="3"/>', $clean);
    }

    public function testTitleAndDescAreKeptAsTextOnly(): void
    {
        // When
        $clean = $this->clean($this->wrap('<title>  Un <b>titre</b> &amp; plus  </title><desc>' . str_repeat('é', 5000) . '</desc>'));

        // Then : le texte seul, échappé, borné à 1 000 caractères
        $this->assertStringContainsString('<title>Un titre &amp; plus</title>', $clean);
        $this->assertStringContainsString('<desc>' . str_repeat('é', SvgSanitizer::MAX_TEXT_LENGTH) . '</desc>', $clean);
    }

    public function testAnInkscapeFileKeepsItsShapesOnly(): void
    {
        // Given
        $svg = <<<'SVG'
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<!-- Created with Inkscape -->
<svg xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:cc="http://creativecommons.org/ns#" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
     xmlns:svg="http://www.w3.org/2000/svg" xmlns="http://www.w3.org/2000/svg" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"
     xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" width="64" height="64" viewBox="0 0 64 64" version="1.1" id="svg8"
     inkscape:version="0.92" sodipodi:docname="icone.svg">
  <sodipodi:namedview id="base" pagecolor="#ffffff" inkscape:zoom="1"/>
  <metadata id="metadata5"><rdf:RDF><cc:Work rdf:about=""><dc:format>image/svg+xml</dc:format></cc:Work></rdf:RDF></metadata>
  <g inkscape:label="Calque 1" inkscape:groupmode="layer" id="layer1">
    <svg:circle cx="32" cy="32" r="20" style="fill:#ff0000;stroke:#000000;stroke-width:2;paint-order:markers" id="circle10" sodipodi:cx="32"/>
  </g>
</svg>
SVG;

        // When
        $clean = $this->clean($svg);

        // Then
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64" version="1.1" id="svg8"><g id="layer1"><circle cx="32" cy="32" r="20" style="fill:#ff0000;stroke:#000000;stroke-width:2" id="circle10"/></g></svg>', $clean);
    }

    // ---------------------------------------------------------------- les `use`

    public function testAUseWithoutATargetInTheFileIsRemoved(): void
    {
        // When
        $clean = $this->clean($this->wrap('<use href="#inconnu"/><use/><use href="#legitime"/>'));

        // Then
        $this->assertSame(1, substr_count($clean, '<use'));
        $this->assertStringContainsString('<use href="#legitime"/>', $clean);
    }

    public function testAUseWhoseTargetContainsAUseIsRemoved(): void
    {
        // Given : un groupe qui contient un `use`, référencé par un autre `use` (la chaîne qui ferait exploser l'affichage)
        $svg = $this->wrap('<g id="a"><use href="#legitime"/></g><use href="#a"/><g id="b"><use href="#a"/></g><use href="#b"/>');

        // When
        $clean = $this->clean($svg);

        // Then : les `use` vers un groupe qui en contient un disparaissent, les `use` vers une forme restent
        $this->assertSame(['<use href="#legitime"/>'], $this->usesIn($clean));
    }

    public function testAUseOfAUseOrOfItsOwnAncestorIsRemoved(): void
    {
        // Given
        $svg = $this->wrap('<use id="u" href="#legitime"/><use href="#u"/><g id="ancetre"><use href="#ancetre"/></g>');

        // When
        $clean = $this->clean($svg);

        // Then
        $this->assertSame(['<use id="u" href="#legitime"/>'], $this->usesIn($clean));
    }

    /**
     * @return list<string>
     */
    private function usesIn(string $svg): array
    {
        preg_match_all('~<use\b[^>]*/>~', $svg, $matches);

        return $matches[0];
    }

    public function testMoreThanOneHundredUsesIsTooLarge(): void
    {
        // Given
        $svg = $this->wrap(str_repeat('<use href="#legitime"/>', SvgSanitizer::MAX_USES + 1));

        // Then
        $this->assertRefused($svg, ImageException::TOO_LARGE);
    }

    public function testOneHundredUsesAreKept(): void
    {
        // When
        $clean = $this->clean($this->wrap(str_repeat('<use href="#legitime"/>', SvgSanitizer::MAX_USES)));

        // Then
        $this->assertSame(SvgSanitizer::MAX_USES, substr_count($clean, '<use '));
    }

    // ---------------------------------------------------------------- le DOCTYPE : simple, ignoré ; sinon refusé

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainDoctypes(): iterable
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="4" height="4" fill="#0a0"/></svg>';

        yield 'l\'en-tête classique d\'Illustrator et d\'Inkscape' => ["<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!-- Generator: Adobe Illustrator 16.0.0, SVG Export Plug-In . SVG Version: 6.00 Build 0)  -->\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n" . $svg];
        yield 'avec un standalone, comme httpbin' => ["<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n" . $svg];
        yield 'SVG 1.0' => ['<?xml version="1.0" standalone="no"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 20010904//EN" "http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd">' . $svg];
        yield 'avec des apostrophes' => ["<!DOCTYPE svg PUBLIC '-//W3C//DTD SVG 1.1//EN' 'http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd'>" . $svg];
        yield 'avec SYSTEM' => ['<!DOCTYPE svg SYSTEM "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . $svg];
        yield 'sans identificateur' => ['<!DOCTYPE svg>' . $svg];
        yield 'des blancs et des retours à la ligne partout' => ["\xEF\xBB\xBF<?xml version=\"1.0\"?>\n  \n<!DOCTYPE\n svg\n PUBLIC\n \"-//W3C//DTD SVG 1.1//EN\"\n \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\"\n >\n" . $svg];
        yield 'après plusieurs commentaires' => ["<!-- un -->\n<!-- deux -->\n<!DOCTYPE svg>\n" . $svg];
        yield 'un identificateur vers un autre site (jamais appelé)' => ['<!DOCTYPE svg PUBLIC "-//EVIL//DTD//EN" "https://evil.example/svg.dtd">' . $svg];
        yield 'un identificateur vers un fichier (jamais lu)' => ['<!DOCTYPE svg SYSTEM "file:///etc/passwd">' . $svg];
    }

    #[DataProvider('plainDoctypes')]
    public function testAPlainDoctypeIsIgnoredAndTheShapesAreKept(string $svg): void
    {
        // When
        $image = $this->sanitizer->sanitize($svg);

        // Then : la forme est là, le DOCTYPE non, l'écriture est celle de la liste blanche
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="4" height="4" fill="#0a0"/></svg>', $image->bytes);
        $this->assertStringNotContainsString('DOCTYPE', $image->bytes);
        $this->assertSame([10, 10], [$image->width, $image->height]);
        $this->assertNothingWasLoaded();
    }

    public function testAnIllustratorFileKeepsItsShapes(): void
    {
        // Given : un export d'Illustrator (DOCTYPE simple, attributs d'éditeur, espace de noms xlink inutilisé)
        $svg = <<<'SVG'
<?xml version="1.0" encoding="utf-8"?>
<!-- Generator: Adobe Illustrator 19.0.0, SVG Export Plug-In . SVG Version: 6.00 Build 0)  -->
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg version="1.1" id="Calque_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
	 width="64px" height="64px" viewBox="0 0 64 64" enable-background="new 0 0 64 64" xml:space="preserve">
<g>
	<circle fill="#FFFFFF" stroke="#231F20" stroke-width="2" stroke-miterlimit="10" cx="32" cy="32" r="20"/>
	<path fill="#E53935" d="M20,32h24"/>
</g>
</svg>
SVG;

        // When
        $image = $this->sanitizer->sanitize($svg);

        // Then
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg" version="1.1" id="Calque_1" x="0px" y="0px" width="64px" height="64px" viewBox="0 0 64 64"><g><circle fill="#FFFFFF" stroke="#231F20" stroke-width="2" stroke-miterlimit="10" cx="32" cy="32" r="20"/><path fill="#E53935" d="M20,32h24"/></g></svg>', $image->bytes);
        $this->assertSame([64, 64], [$image->width, $image->height]);
        $this->assertNothingWasLoaded();
    }

    public function testTheOutputOfADocumentWithADoctypeIsIdempotent(): void
    {
        // Given
        $svg = "<?xml version=\"1.0\"?>\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n" . Images::svg();

        // When
        $once = $this->sanitizer->sanitize($svg);
        $twice = $this->sanitizer->sanitize($once->bytes);

        // Then : la sortie n'a pas de DOCTYPE et son assainissement ne change rien
        $this->assertStringNotContainsString('DOCTYPE', $once->bytes);
        $this->assertSame($once->bytes, $twice->bytes);
        $this->assertSame($once->fileName(), $this->sanitizer->sanitize(Images::svg())->fileName(), 'Avec ou sans DOCTYPE, le même dessin est le même fichier.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileDocuments(): iterable
    {
        yield 'une entité externe qui lit un fichier (XXE)' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file://SECRET">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>&xxe;</title><rect width="1" height="1"/></svg>'];
        yield 'une entité externe qui appelle le réseau' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "http://TRAP/entite">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>&xxe;</title><rect width="1" height="1"/></svg>'];
        yield 'les métadonnées d\'un nuage' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "http://169.254.169.254/latest/meta-data/">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>&xxe;</title><rect width="1" height="1"/></svg>'];
        yield 'une entité de paramètre qui charge une DTD' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY % dtd SYSTEM "http://TRAP/x.dtd"> %dtd;]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>'];
        yield 'une DTD distante ET une entité' => ['<!DOCTYPE svg SYSTEM "http://TRAP/x.dtd" [<!ENTITY a "b">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>'];
        yield 'des entités qui se multiplient (billion laughs)' => [self::billionLaughs()];
        yield 'une feuille de style distante' => ['<?xml version="1.0"?><?xml-stylesheet href="http://TRAP/x.css" type="text/css"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>'];
        yield 'une feuille de style distante après la racine' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg><?xml-stylesheet href="http://TRAP/x.css"?>'];
        yield 'une feuille de style qui est un fichier' => ['<?xml-stylesheet href="file://SECRET" type="text/css"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>'];
        yield 'une feuille de style avec des apostrophes' => ["<?xml-stylesheet type='text/css' href='//TRAP/x.css'?><svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 10 10\"><rect width=\"1\" height=\"1\"/></svg>"];
    }

    #[DataProvider('hostileDocuments')]
    public function testEntitiesDtdAndRemoteStylesheetsAreRefusedWithoutAnyAccess(string $svg): void
    {
        // Given : un fichier secret réel et un serveur d'essai réel, nommés dans le document
        $secret = tempnam(sys_get_temp_dir(), 'secret');
        file_put_contents($secret, 'SECRET-DU-FICHIER');
        $svg = str_replace(['file://SECRET', 'TRAP'], ['file://' . $secret, $this->trapAddress()], $svg);

        try {
            // When / Then : refusé (jamais une image gardée, rien du secret)
            try {
                $image = $this->sanitizer->sanitize($svg);
                $this->fail('Le document aurait dû être refusé, il a donné : ' . $image->bytes);
            } catch (ImageException $exception) {
                $this->assertContains($exception->getMessage(), [ImageException::UNSUPPORTED, ImageException::NOT_AN_IMAGE]);
                $this->assertStringNotContainsString('SECRET', $exception->getMessage());
            }

            // Et : ni fichier lu, ni DTD ou entité chargée, ni connexion reçue
            $this->assertNothingWasLoaded();
        } finally {
            unlink($secret);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedDoctypes(): iterable
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>';

        yield 'un sous-ensemble interne vide' => ['<!DOCTYPE svg []>' . $svg, ImageException::UNSUPPORTED];
        yield 'un sous-ensemble interne sans entité' => ['<!DOCTYPE svg [<!ELEMENT svg ANY>]>' . $svg, ImageException::UNSUPPORTED];
        yield 'un sous-ensemble interne avec PUBLIC' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" [<!ELEMENT svg ANY>]>' . $svg, ImageException::UNSUPPORTED];
        yield 'une entité interne (le piège d\'Illustrator ancien)' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" [<!ENTITY ns_extend "http://ns.adobe.com/Extensibility/1.0/">]>' . $svg, ImageException::UNSUPPORTED];
        yield 'une entité en minuscules' => ['<!doctype svg [ <!entity a "b"> ]>' . $svg, ImageException::UNSUPPORTED];
        yield 'une autre racine' => ['<!DOCTYPE html>' . $svg, ImageException::UNSUPPORTED];
        yield 'une autre racine avec PUBLIC' => ['<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">' . $svg, ImageException::UNSUPPORTED];
        yield 'le mot-clé en minuscules (le XML est sensible à la casse)' => ['<!doctype svg>' . $svg, ImageException::UNSUPPORTED];
        yield 'un nom de racine en majuscules' => ['<!DOCTYPE SVG>' . $svg, ImageException::UNSUPPORTED];
        yield 'deux DOCTYPE' => ['<!DOCTYPE svg><!DOCTYPE svg>' . $svg, ImageException::UNSUPPORTED];
        yield 'un DOCTYPE dans un commentaire' => ['<!-- <!DOCTYPE svg> -->' . $svg, ImageException::UNSUPPORTED];
        yield 'un DOCTYPE après la racine' => [$svg . '<!DOCTYPE svg>', ImageException::UNSUPPORTED];
        yield 'un DOCTYPE dans le contenu' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title><!DOCTYPE svg></title><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un DOCTYPE après un élément' => ['<?xml version="1.0"?><x/><!DOCTYPE svg>' . $svg, ImageException::UNSUPPORTED];
        yield 'un identificateur avec un crochet' => ['<!DOCTYPE svg PUBLIC "a[b" "c">' . $svg, ImageException::UNSUPPORTED];
        yield 'un identificateur avec un chevron' => ['<!DOCTYPE svg SYSTEM "a>b">' . $svg, ImageException::UNSUPPORTED];
        yield 'un DOCTYPE qui n\'est pas fermé' => ['<!DOCTYPE svg ' . $svg, ImageException::UNSUPPORTED];
        yield 'une entité déclarée dans un commentaire (au moindre doute)' => ['<!-- <!ENTITY a "b"> -->' . $svg, ImageException::UNSUPPORTED];
        yield 'une référence d\'entité personnalisée, sans déclaration' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>&xxe;</title><rect width="1" height="1"/></svg>', ImageException::NOT_AN_IMAGE];
        yield 'une référence d\'entité personnalisée derrière un DOCTYPE simple' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>&ns_extend;</title><rect width="1" height="1"/></svg>', ImageException::NOT_AN_IMAGE];
    }

    #[DataProvider('refusedDoctypes')]
    public function testAnythingButAPlainDoctypeInThePrologueIsRefused(string $svg, string $message): void
    {
        // When / Then
        $this->assertRefused($svg, $message);
        $this->assertNothingWasLoaded();
    }

    public function testADoctypeHiddenInUtf16IsRefusedToo(): void
    {
        // Given : le même piège écrit en UTF-16 (les expressions de repérage des octets ne le voient pas, l'analyseur, si), avec un serveur d'essai et un fichier
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "http://' . $this->trapAddress() . '/x">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>&xxe;</title><rect width="1" height="1"/></svg>';
        $utf16 = "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');

        // Then
        $this->assertRefused($utf16, ImageException::UNSUPPORTED);
        $this->assertNothingWasLoaded();
    }

    public function testAPlainDoctypeInUtf16IsRefusedToo(): void
    {
        // Given : l'ignorer demande de le reconnaître dans le texte ; dans un autre encodage il reste vu par l'analyseur, qui le refuse
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>';

        // Then
        $this->assertRefused("\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'), ImageException::UNSUPPORTED);
        $this->assertNothingWasLoaded();
    }

    public function testBillionLaughsIsRefusedWithoutBeingExpanded(): void
    {
        // Given
        $memory = memory_get_peak_usage(true);
        $started = hrtime(true);

        // Then : refusé sans que l'analyseur n'ait déplié quoi que ce soit (mémoire et temps)
        $this->assertRefused(self::billionLaughs(), ImageException::UNSUPPORTED);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $memory);
        $this->assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
        $this->assertNothingWasLoaded();
    }

    public function testAnUndefinedEntityIsNotAnImage(): void
    {
        // Then : pas de DOCTYPE, donc pas de définition : le XML n'est pas bien formé
        $this->assertRefused(self::OPEN . '<title>&inconnue;</title><rect width="1" height="1"/></svg>', ImageException::NOT_AN_IMAGE);
    }

    public function testThePredefinedEntitiesAndCharacterReferencesStillWork(): void
    {
        // When
        $clean = $this->clean($this->wrap('<title>&lt;a&gt; &amp; &#233; &#x41;</title>'));

        // Then
        $this->assertStringContainsString('<title>&lt;a&gt; &amp; é A</title>', $clean);
    }

    public function testAStylesheetPointingInsideTheFileIsIgnored(): void
    {
        // When : une feuille de style interne, ou sans adresse, ne charge rien : elle disparaît avec les instructions de traitement
        $clean = $this->clean('<?xml-stylesheet href="#style" type="text/css"?>' . $this->wrap(''));

        // Then
        $this->assertStringNotContainsString('stylesheet', $clean);
        $this->assertStringContainsString('<rect id="legitime"', $clean);
        $this->assertNothingWasLoaded();
    }

    public function testTheTrapsWork(): void
    {
        // Given : le même piège que ci-dessus, mais avec un analyseur qui chargerait la DTD
        $document = new DOMDocument();
        @$document->loadXML('<!DOCTYPE svg SYSTEM "http://' . $this->trapAddress() . '/x.dtd"><svg/>', LIBXML_DTDLOAD);

        // Then : le chargeur de libxml a été appelé, avec l'adresse
        $this->assertSame(['http://' . $this->trapAddress() . '/x.dtd'], $this->externalLoads);

        // Et le serveur d'essai reçoit bien une connexion quand on s'y connecte
        $client = stream_socket_client('tcp://' . $this->trapAddress());
        $this->assertNotFalse(@stream_socket_accept($this->listener, 0));
        fclose($client);
        $this->externalLoads = [];
    }

    private static function billionLaughs(): string
    {
        $entities = '<!ENTITY a0 "lol">';
        for ($i = 1; $i <= 9; ++$i) {
            $entities .= sprintf('<!ENTITY a%d "%s">', $i, str_repeat('&a' . ($i - 1) . ';', 10));
        }

        return '<?xml version="1.0"?><!DOCTYPE svg [' . $entities . ']><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>&a9;</title><rect width="1" height="1"/></svg>';
    }
    // ---------------------------------------------------------------- les plafonds de complexité

    public function testTenThousandElementsAreAccepted(): void
    {
        // Given : la racine plus 9 999 formes
        $svg = self::OPEN . str_repeat('<rect width="1" height="1"/>', SvgSanitizer::MAX_ELEMENTS - 1) . '</svg>';

        // When
        $clean = $this->clean($svg);

        // Then
        $this->assertSame(SvgSanitizer::MAX_ELEMENTS - 1, substr_count($clean, '<rect'));
    }

    public function testMoreThanTenThousandElementsIsTooLargeAndIsRefusedWhileStreaming(): void
    {
        // Given : 5 millions d'éléments tiendraient dans 3 Mio... pas tout à fait : 3 Mio de `<g/>`, plusieurs centaines de milliers d'éléments
        $svg = self::OPEN . str_repeat('<g/>', 600_000) . '</svg>';
        $this->assertLessThan(3 * 1024 * 1024, strlen($svg));
        $memory = memory_get_peak_usage(true);

        // Then : refusé sans construire d'arbre
        $this->assertRefused($svg, ImageException::TOO_LARGE);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $memory);
    }

    public function testAnElementPastTheLimitIsTooLargeEvenIfItWouldBeDropped(): void
    {
        // Given : des éléments inconnus, qui seraient retirés, en nombre
        $svg = self::OPEN . '<rect width="1" height="1"/>' . str_repeat('<inconnu/>', SvgSanitizer::MAX_ELEMENTS) . '</svg>';

        // Then
        $this->assertRefused($svg, ImageException::TOO_LARGE);
    }

    public function testThirtyTwoLevelsAreAcceptedAndThirtyThreeAreTooDeep(): void
    {
        // Given : la racine fait un niveau
        $levels = static fn (int $count): string => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">' . str_repeat('<g>', $count - 1) . '<rect width="1" height="1"/>' . str_repeat('</g>', $count - 1) . '</svg>';

        // When / Then
        $this->assertStringContainsString('<rect', $this->clean($levels(SvgSanitizer::MAX_DEPTH - 1)));
        $this->assertRefused($levels(SvgSanitizer::MAX_DEPTH + 1), ImageException::TOO_LARGE);
        $this->assertRefused($levels(5000), ImageException::TOO_LARGE);
    }

    public function testTooManyAttributesOnOneElementIsTooLarge(): void
    {
        // Given
        $attributes = '';
        for ($i = 0; $i <= SvgSanitizer::MAX_ATTRIBUTES; ++$i) {
            $attributes .= sprintf(' a%d="1"', $i);
        }

        // Then
        $this->assertRefused(self::OPEN . '<rect width="1" height="1"' . $attributes . '/></svg>', ImageException::TOO_LARGE);
    }

    public function testAValueThatIsTooLongIsDropped(): void
    {
        // When
        $clean = $this->clean($this->wrap('<path d="' . str_repeat('M0 0 ', SvgSanitizer::MAX_VALUE_LENGTH) . '"/><path d="M0 0h1"/>'));

        // Then : le tracé trop long perd son attribut `d`, l'autre garde le sien
        $this->assertSame(1, substr_count($clean, ' d="'));
        $this->assertStringContainsString('<path d="M0 0h1"/>', $clean);
        $this->assertLessThan(1000, strlen($clean));
    }

    // ---------------------------------------------------------------- ce qui est refusé

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'vide' => ['', ImageException::NOT_AN_IMAGE];
        yield 'des blancs' => ["  \n ", ImageException::NOT_AN_IMAGE];
        yield 'du texte' => ['pas du XML', ImageException::NOT_AN_IMAGE];
        yield 'un XML mal formé' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>', ImageException::NOT_AN_IMAGE];
        yield 'un XML coupé' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1"', ImageException::NOT_AN_IMAGE];
        yield 'un octet invalide en UTF-8' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>' . "\xFF\xFE" . '</title><rect width="1" height="1"/></svg>', ImageException::NOT_AN_IMAGE];
        yield 'une racine qui n\'est pas svg' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>', ImageException::UNSUPPORTED];
        yield 'une autre racine' => ['<document><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg></document>', ImageException::UNSUPPORTED];
        yield 'un svg sans espace de noms' => ['<svg viewBox="0 0 10 10"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un svg dans un autre espace de noms' => ['<svg xmlns="http://example.org/autre" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un svg en XHTML' => ['<svg xmlns="http://www.w3.org/1999/xhtml" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'aucune taille' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'une largeur sans hauteur' => ['<svg xmlns="http://www.w3.org/2000/svg" width="10"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'des pourcentages' => ['<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un viewBox nul' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un viewBox négatif' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 -5 10"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un viewBox à trois nombres' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un viewBox qui n\'est pas un nombre' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="a b c d"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'une taille démesurée' => ['<svg xmlns="http://www.w3.org/2000/svg" width="100000" height="100000"><rect width="1" height="1"/></svg>', ImageException::UNSUPPORTED];
        yield 'un svg vide' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"/>', ImageException::UNSUPPORTED];
        yield 'un svg dont tout le contenu est retiré' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><image href="data:image/png;base64,AAAA"/></svg>', ImageException::UNSUPPORTED];
        yield 'un svg qui ne contient que du texte' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>Bonjour</text></svg>', ImageException::UNSUPPORTED];
        yield 'un svg qui ne contient que des groupes vides' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><g><g/></g><defs/></svg>', ImageException::UNSUPPORTED];
    }

    #[DataProvider('refused')]
    public function testWhatCannotBecomeAUsableSvgIsRefused(string $svg, string $message): void
    {
        $this->assertRefused($svg, $message);
    }

    public function testTheRefusalsNeverCiteTheContent(): void
    {
        // When
        try {
            $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><title>SECRET-DU-CONTENU</title></svg>');
            $this->fail('Le SVG aurait dû être refusé.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame([422, 'IMAGE_UNSUPPORTED'], [$exception->getCode(), $exception->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- idempotence, déterminisme

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'un SVG propre' => [Images::svg()];
        yield 'avec un prologue' => [Images::svgWithPrologue()];
        yield 'un SVG piégé' => [self::OPEN . '<rect id="a" width="4" height="4" fill="#0a0" onclick="x()" style="fill:red;behavior:url(x)"/><script>alert(1)</script><use xlink:href="#a"/><style>.a{fill:red}</style><title>  t  </title><a href="x"><g/></a></svg>'];
        yield 'avec des références' => [self::OPEN . '<defs><linearGradient id="g" xlink:href="#h"/><linearGradient id="h"/></defs><rect width="3" height="3" fill="url( #g )" style=" FILL : url(#g) ;stroke: red "/></svg>'];
        yield 'avec des retours à la ligne dans les attributs' => [self::OPEN . "<path d=\"M0 0\n  L5 5\n\tL0 5z\" fill=\"red\"/></svg>"];
        yield 'avec des caractères accentués' => [self::OPEN . '<title>Fête à Noël — œuf</title><circle r="2"/></svg>'];
        yield 'avec du CSS contenant > et &' => [self::OPEN . '<style>g > rect[width="1"] { fill: red }</style><g><rect width="1" height="1"/></g></svg>'];
    }

    #[DataProvider('documents')]
    public function testSanitizingTheResultAgainChangesNothing(string $svg): void
    {
        // When
        $once = $this->sanitizer->sanitize($svg);
        $twice = $this->sanitizer->sanitize($once->bytes);

        // Then
        $this->assertSame($once->bytes, $twice->bytes);
        $this->assertSame($once->fileName(), $twice->fileName());
        $this->assertSame([$once->width, $once->height], [$twice->width, $twice->height]);
    }

    public function testTheSameContentWrittenDifferentlyGivesTheSameFile(): void
    {
        // Given : le même dessin, avec des blancs, un commentaire, un prologue et des ajouts retirés
        $compact = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="red"/></svg>';
        $spread = "<?xml version=\"1.0\"?>\n<!-- un commentaire -->\n<svg  viewBox=\"0 0 10 10\"   xmlns=\"http://www.w3.org/2000/svg\" onload=\"alert(1)\">\n\n  <circle\n  cx=\"5\"\n cy=\"5\" r=\"4\" fill=\"red\"  data-x=\"1\"/>\n  <script>alert(1)</script>\n</svg>\n";

        // When / Then : les attributs gardent l'ordre du fichier, les blancs et ajouts disparaissent
        $this->assertSame($this->clean($compact), $this->clean(str_replace('<svg  viewBox="0 0 10 10"   xmlns="http://www.w3.org/2000/svg" onload="alert(1)">', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">', $spread)));
        $this->assertSame($this->sanitizer->sanitize($compact)->fileName(), $this->sanitizer->sanitize($compact . "\n")->fileName());
    }

    public function testTheOutputNeverEchoesTheOriginalText(): void
    {
        // Given : des séquences que seul le texte d'origine peut reproduire
        $svg = "<?xml version=\"1.0\" standalone=\"no\"?><!-- COMMENTAIRE -->\n<svg   xmlns=\"http://www.w3.org/2000/svg\"\n viewBox='0 0 10 10'><rect   width='1' height=\"1\"   /></svg>";

        // When
        $clean = $this->clean($svg);

        // Then : guillemets, espaces et fins de ligne réécrits
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>', $clean);
    }
}
