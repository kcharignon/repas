<?php

namespace Repas\Repas\Domain\Service;


use DOMDocument;
use DOMElement;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Model\ProcessedImage;
use XMLReader;
use XMLWriter;

/**
 * Assainit un SVG envoyé par l'administrateur (ADR 0039, décision « SVG accepté après assainissement par liste blanche »). Un SVG est du XML qui peut porter
 * un script, charger une ressource distante ou une autre page ; servi depuis NOTRE origine et ouvert directement, il s'exécuterait. Il n'est donc JAMAIS gardé
 * tel qu'il arrive : le fichier est lu, ce qui est connu et sûr est copié dans un arbre neuf, et c'est cet arbre qui est ré-écrit.
 *
 * 1. **Lecture sans danger** : aucune entité, aucune DTD chargée, jamais `LIBXML_NOENT`, `LIBXML_DTDLOAD` ni `LIBXML_DTDATTR`, `LIBXML_NONET` : pas de XXE,
 *    pas de « billion laughs », aucun accès au réseau ni aux fichiers. **Un `DOCTYPE` simple est accepté et IGNORÉ** (l'en-tête classique d'Illustrator ou
 *    d'Inkscape, `<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">`, ou `SYSTEM "…"`) : il est retiré du
 *    texte AVANT l'analyse, qui ne le voit jamais ; la sortie n'en a pas. Sont refusés (`unsupported`) : un `DOCTYPE` avec sous-ensemble interne (`[ … ]`) ou
 *    d'une autre forme, plus d'un `DOCTYPE`, tout `<!ENTITY` (interne ou externe), toute référence d'entité personnalisée (`&xxe;` : le XML n'est pas bien
 *    formé sans déclaration), une instruction `<?xml-stylesheet?>` dont l'`href` n'est pas interne. Avant de construire un arbre, un passage en flux
 *    (`XMLReader`) compte les éléments et la profondeur et s'arrête au premier dépassement : au plus 10 000 éléments et 32 niveaux (`tooLarge`).
 * 2. **Liste blanche d'éléments** (espace de noms SVG seulement) : `svg g path circle ellipse rect line polyline polygon defs linearGradient radialGradient
 *    stop clipPath mask symbol use title desc style`. Tout autre élément, et tout ce qu'il contient, est retiré : jamais `script`, `foreignObject`,
 *    `iframe`, `image`, `animate`, `set`, `a`, ni un élément d'un autre espace de noms (Inkscape, métadonnées).
 * 3. **Liste blanche d'attributs** : géométrie et présentation (`d`, `fill`, `stroke`, `transform`, `viewBox`, `class`, `id`…), jamais un `on…`. `href` (ou
 *    `xlink:href`, ramené à `href`) n'est gardé que sur `use` et les dégradés, et seulement comme référence INTERNE `#id`. Une valeur qui contient
 *    `javascript:`, `data:`, `vbscript:`, une barre oblique inverse ou un `url(…)` qui n'est pas `url(#id)` fait retirer l'attribut. `style` (attribut) est
 *    refait déclaration par déclaration, sur une liste blanche de propriétés de présentation.
 * 4. **`<style>`** n'est gardé que si son CSS est sûr : ni `@` (`@import`, `@font-face`), ni `\`, ni commentaire, ni `url(` autre que `url(#id)`,
 *    ni `expression`, `behavior`, `-moz-binding`, `javascript:` ; 20 Ko et 5 éléments au plus. Sinon l'élément est retiré.
 * 5. **`use` borné** : 100 au plus, vers un élément du même fichier qui ne contient lui-même aucun `use` (pas d'explosion exponentielle à l'affichage).
 * 6. **Ré-écriture** : la sortie est produite par `XMLWriter` depuis l'arbre assaini (jamais le texte d'origine), sans déclaration XML, sans commentaire ni
 *    instruction de traitement, avec `xmlns` posé par nous : sa sortie est identique si on l'assainit de nouveau (idempotente), et le même contenu donne
 *    le même fichier.
 * 7. **Exploitable** : la racine doit être `svg` (espace de noms SVG), avoir un `viewBox` ou une largeur et une hauteur utilisables, et il doit rester au
 *    moins une forme à dessiner ; sinon `unsupported`.
 *
 * Le fichier est servi avec `Content-Type: image/svg+xml`, `nosniff` et une politique `default-src 'none'; style-src 'unsafe-inline'; sandbox` : même
 * ouvert directement, un SVG ne peut rien exécuter (frankenphp/Caddyfile). Ce service ne fait que rendre une image ou lever `ImageException`.
 */
final readonly class SvgSanitizer
{
    public const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    public const int MAX_ELEMENTS = 10_000;
    public const int MAX_DEPTH = 32;
    public const int MAX_ATTRIBUTES = 200;
    public const int MAX_USES = 100;
    public const int MAX_STYLES = 5;
    public const int MAX_STYLE_BYTES = 20_000;
    public const int MAX_VALUE_LENGTH = 100_000;
    public const int MAX_TEXT_LENGTH = 1_000;
    /** La plus grande largeur ou hauteur écrite en unités (`width="…"`) que l'on garde comme taille. */
    public const int MAX_SIDE = 16_384;

    private const string XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';
    private const string REFERENCE = '#[A-Za-z_][A-Za-z0-9_.:-]{0,99}';
    /** Le prologue d'un document : marque d'ordre des octets, blancs, déclaration XML et commentaires (aucun DOCTYPE, aucune entité, aucun élément). */
    private const string PROLOG = '~\A(?:\xEF\xBB\xBF)?(?>[ \t\r\n]|<\?xml(?=[ \t\r\n])[^>]*\?>|<!--(?:(?!--).)*-->)*+\z~s';
    /** Un `DOCTYPE` simple d'un SVG, tel qu'on l'ignore : jamais de sous-ensemble interne (`[`), jamais de `<` ni `>` dans les identificateurs. */
    private const string PLAIN_DOCTYPE = '~\G<!DOCTYPE[ \t\r\n]+svg(?:[ \t\r\n]+PUBLIC[ \t\r\n]+(?:"[^"<>\[\]]*"|\'[^\'<>\[\]]*\')[ \t\r\n]+(?:"[^"<>\[\]]*"|\'[^\'<>\[\]]*\')|[ \t\r\n]+SYSTEM[ \t\r\n]+(?:"[^"<>\[\]]*"|\'[^\'<>\[\]]*\'))?[ \t\r\n]*>~';

    /** @var array<string, true> */
    private const array ELEMENTS = [
        'svg' => true, 'g' => true, 'path' => true, 'circle' => true, 'ellipse' => true, 'rect' => true, 'line' => true, 'polyline' => true,
        'polygon' => true, 'defs' => true, 'linearGradient' => true, 'radialGradient' => true, 'stop' => true, 'clipPath' => true, 'mask' => true,
        'symbol' => true, 'use' => true, 'title' => true, 'desc' => true, 'style' => true,
    ];

    /** Les formes qui se dessinent : il doit en rester au moins une. */
    private const array SHAPES = ['path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'use'];
    /** Les éléments qui peuvent porter une référence `href` interne. */
    private const array REFERENCING = ['use', 'linearGradient', 'radialGradient'];

    /** @var array<string, true> */
    private const array ATTRIBUTES = [
        'id' => true, 'class' => true, 'style' => true, 'href' => true, 'viewBox' => true, 'preserveAspectRatio' => true, 'width' => true, 'height' => true,
        'x' => true, 'y' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'cx' => true, 'cy' => true, 'r' => true, 'rx' => true,
        'ry' => true, 'fx' => true, 'fy' => true, 'fr' => true, 'd' => true, 'points' => true, 'transform' => true, 'offset' => true,
        'fill' => true, 'fill-opacity' => true, 'fill-rule' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-opacity' => true,
        'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-miterlimit' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'opacity' => true, 'color' => true, 'display' => true, 'visibility' => true, 'clip-path' => true, 'clip-rule' => true, 'mask' => true,
        'stop-color' => true, 'stop-opacity' => true, 'gradientUnits' => true, 'gradientTransform' => true, 'spreadMethod' => true,
        'clipPathUnits' => true, 'maskUnits' => true, 'maskContentUnits' => true, 'version' => true,
    ];

    /** Les propriétés de présentation qu'un attribut `style` peut porter. @var array<string, true> */
    private const array CSS_PROPERTIES = [
        'fill' => true, 'fill-opacity' => true, 'fill-rule' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-opacity' => true,
        'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-miterlimit' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'opacity' => true, 'color' => true, 'display' => true, 'visibility' => true, 'clip-path' => true, 'clip-rule' => true, 'mask' => true,
        'stop-color' => true, 'stop-opacity' => true,
    ];

    /** Ce qui fait refuser une valeur, une fois ses espaces retirés et passée en minuscules. */
    private const array FORBIDDEN_FRAGMENTS = ['javascript:', 'vbscript:', 'data:', 'expression(', 'behavior', '-moz-binding', '@import'];

    /**
     * @throws ImageException `notAnImage` (XML mal formé, entité non déclarée), `unsupported` (DOCTYPE qui n'est pas simple, entité déclarée, feuille de style
     *                        distante, racine qui n'est pas un SVG, rien d'exploitable après assainissement), `tooLarge` (trop d'éléments, trop profond)
     */
    public function sanitize(string $svg): ProcessedImage
    {
        if (trim($svg) === '') {
            throw ImageException::notAnImage();
        }
        $svg = $this->withoutPlainDoctype($svg);

        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertSimpleEnough($svg);
            $root = $this->parse($svg);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $state = ['ids' => [], 'uses' => 0, 'styles' => 0];
        $tree = $this->copy($root, 0, $state);
        if ($tree === null) {
            throw ImageException::unsupported();
        }

        $tree = $this->withoutUnsafeUses($tree);
        $size = $this->size($tree);
        if ($size === null || !$this->hasShape($tree)) {
            throw ImageException::unsupported();
        }

        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(false);
        $this->write($writer, $tree, true);

        return ProcessedImage::of($writer->outputMemory(), ProcessedImage::SVG, $size[0], $size[1]);
    }

    /**
     * Retire du texte le `DOCTYPE` d'un SVG ordinaire, avant que l'analyseur ne le voie : `<!DOCTYPE svg>`, `<!DOCTYPE svg PUBLIC "…" "…">` ou
     * `<!DOCTYPE svg SYSTEM "…">`, sans sous-ensemble interne, dans le prologue (après la déclaration XML, des commentaires et des blancs, avant la racine).
     * Les identificateurs ne sont que des chaînes : rien n'est jamais chargé. Tout le reste est refusé : un `DOCTYPE` d'une autre forme (sous-ensemble interne
     * `[ … ]`, autre racine, minuscules), plus d'un, un `DOCTYPE` qui n'est pas dans le prologue (dans un commentaire, par exemple) et tout `<!ENTITY`, même
     * dans un commentaire (au moindre doute on refuse).
     *
     * @throws ImageException `unsupported`
     */
    private function withoutPlainDoctype(string $svg): string
    {
        if (preg_match('/<!ENTITY/i', $svg) === 1) {
            throw ImageException::unsupported();
        }

        $count = preg_match_all('/<!DOCTYPE/i', $svg, $found, PREG_OFFSET_CAPTURE);
        if ($count === 0) {
            return $svg;
        }
        if ($count !== 1) {
            throw ImageException::unsupported();
        }

        $offset = $found[0][0][1];
        if (preg_match(self::PROLOG, substr($svg, 0, $offset)) !== 1 || preg_match(self::PLAIN_DOCTYPE, $svg, $doctype, 0, $offset) !== 1) {
            throw ImageException::unsupported();
        }

        return substr($svg, 0, $offset) . substr($svg, $offset + strlen($doctype[0]));
    }

    /**
     * Un passage en flux avant toute construction d'arbre : le document est bien formé, il n'a ni DOCTYPE restant (un autre encodage que celui qu'on a su lire),
     * ni entité, ni feuille de style distante, et il tient dans les plafonds.
     *
     * @throws ImageException
     */
    private function assertSimpleEnough(string $svg): void
    {
        $reader = XMLReader::XML($svg, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$reader instanceof XMLReader) {
            throw ImageException::notAnImage();
        }

        $elements = 0;
        try {
            while ($reader->read()) {
                switch ($reader->nodeType) {
                    case XMLReader::DOC_TYPE:
                    case XMLReader::ENTITY_REF:
                    case XMLReader::ENTITY:
                        throw ImageException::unsupported();
                    case XMLReader::PI:
                        if ($reader->name === 'xml-stylesheet' && !$this->isInternalStylesheet($reader->value)) {
                            throw ImageException::unsupported();
                        }
                        break;
                    case XMLReader::ELEMENT:
                        if (++$elements > self::MAX_ELEMENTS || $reader->depth >= self::MAX_DEPTH || $reader->attributeCount > self::MAX_ATTRIBUTES) {
                            throw ImageException::tooLarge();
                        }
                        break;
                }
            }
        } finally {
            $reader->close();
        }

        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                throw ImageException::notAnImage();
            }
        }
        if ($elements === 0) {
            throw ImageException::notAnImage();
        }
    }

    /** Une feuille de style `<?xml-stylesheet href="#id"?>` renvoie à ce fichier ; sans `href`, il n'y a rien à charger. Toute autre adresse est distante. */
    private function isInternalStylesheet(string $data): bool
    {
        if (preg_match('/href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $data, $href) !== 1) {
            return true;
        }

        return str_starts_with($href[1] !== '' ? $href[1] : ($href[2] ?? ''), '#');
    }

    /**
     * @throws ImageException
     */
    private function parse(string $svg): DOMElement
    {
        $document = new DOMDocument();
        // Ni LIBXML_NOENT (les entités ne sont jamais remplacées), ni LIBXML_DTDLOAD, ni LIBXML_DTDATTR : aucune DTD, aucun chargement externe
        if (!$document->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw ImageException::notAnImage();
        }
        if ($document->doctype !== null) {
            throw ImageException::unsupported();
        }

        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NAMESPACE) {
            throw ImageException::unsupported();
        }

        return $root;
    }

    // ---------------------------------------------------------------- l'arbre assaini

    /**
     * Copie `$source` dans un arbre neuf s'il est sur la liste blanche, `null` sinon (lui et tout ce qu'il contient disparaissent).
     *
     * @param array{ids: array<string, true>, uses: int, styles: int} $state
     *
     * @return array{name: string, attrs: array<string, string>, children: list<array<string, mixed>>, text: string}|null
     *
     * @throws ImageException
     */
    private function copy(DOMElement $source, int $depth, array &$state): ?array
    {
        $name = $source->localName;
        if ($source->namespaceURI !== self::SVG_NAMESPACE || !isset(self::ELEMENTS[$name])) {
            return null;
        }
        if ($depth >= self::MAX_DEPTH) {
            throw ImageException::tooLarge();
        }

        if ($name === 'style') {
            $css = trim($source->textContent);
            if (++$state['styles'] > self::MAX_STYLES || $css === '' || !$this->isSafeCss($css)) {
                return null;
            }

            return ['name' => 'style', 'attrs' => [], 'children' => [], 'text' => $css];
        }

        if ($name === 'title' || $name === 'desc') {
            $text = trim((string) preg_replace('/[^\P{C}\n\t]+/u', '', $source->textContent));

            return ['name' => $name, 'attrs' => [], 'children' => [], 'text' => mb_substr($text, 0, self::MAX_TEXT_LENGTH)];
        }

        if ($name === 'use' && ++$state['uses'] > self::MAX_USES) {
            throw ImageException::tooLarge();
        }

        $children = [];
        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $copy = $this->copy($child, $depth + 1, $state);
                if ($copy !== null) {
                    $children[] = $copy;
                }
            }
        }

        return ['name' => $name, 'attrs' => $this->attributes($source, $name, $state), 'children' => $children, 'text' => ''];
    }

    /**
     * @param array{ids: array<string, true>, uses: int, styles: int} $state
     *
     * @return array<string, string>
     */
    private function attributes(DOMElement $source, string $element, array &$state): array
    {
        $kept = [];
        foreach ($source->attributes as $attribute) {
            $namespace = $attribute->namespaceURI;
            $key = $attribute->nodeName;
            if ($namespace === self::XLINK_NAMESPACE && $attribute->localName === 'href') {
                $key = 'href'; // SVG 2 : `href` suffit, la sortie n'a pas de préfixe `xlink`
            } elseif ($namespace !== null && $namespace !== '') {
                continue; // `xml:space`, `inkscape:…`, `sodipodi:…` : rien d'autre qu'un attribut sans espace de noms
            }
            if (!isset(self::ATTRIBUTES[$key]) || isset($kept[$key])) {
                continue;
            }

            $value = $attribute->value;
            $safe = match ($key) {
                'style' => $this->safeStyle($value),
                'href' => in_array($element, self::REFERENCING, true) ? $this->internalReference($value) : null,
                'id' => $this->uniqueId($value, $state),
                'class' => $this->safeClasses($value),
                default => $this->isSafeValue($value) ? trim($value) : null,
            };
            if ($safe !== null && $safe !== '') {
                $kept[$key] = $safe;
            }
        }

        return $kept;
    }

    /** Une valeur sans script, sans adresse distante et sans `url(…)` qui ne soit pas une référence interne. */
    private function isSafeValue(string $value): bool
    {
        if (strlen($value) > self::MAX_VALUE_LENGTH || str_contains($value, '\\')) {
            return false;
        }

        $compact = strtolower((string) preg_replace('/[\s\x00-\x1f]+/', '', $value));
        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($compact, $fragment)) {
                return false;
            }
        }

        return !$this->hasExternalUrl($value);
    }

    /** Un `url(` qui reste une fois les références internes `url(#id)` retirées est une ressource distante, ou pire. */
    private function hasExternalUrl(string $text): bool
    {
        $withoutInternal = (string) preg_replace('/url\(\s*' . self::REFERENCE . '\s*\)/i', '', $text);

        return preg_match('/url\s*\(/i', $withoutInternal) === 1;
    }

    private function internalReference(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^' . self::REFERENCE . '$/', $value) === 1 ? $value : null;
    }

    /**
     * @param array{ids: array<string, true>, uses: int, styles: int} $state
     */
    private function uniqueId(string $value, array &$state): ?string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,99}$/', $value) !== 1 || isset($state['ids'][$value])) {
            return null;
        }
        $state['ids'][$value] = true;

        return $value;
    }

    private function safeClasses(string $value): ?string
    {
        $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}$/', $token) !== 1) {
                return null;
            }
        }

        return implode(' ', $tokens);
    }

    /** Un attribut `style` refait déclaration par déclaration : propriété de présentation connue, valeur sûre. */
    private function safeStyle(string $style): ?string
    {
        if (strlen($style) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        $declarations = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (!isset(self::CSS_PROPERTIES[$property]) || $value === '' || preg_match('/["\'{}]/', $value) === 1 || !$this->isSafeValue($value)) {
                continue;
            }
            $declarations[] = $property . ':' . $value;
        }

        return $declarations === [] ? null : implode(';', $declarations);
    }

    private function isSafeCss(string $css): bool
    {
        if (strlen($css) > self::MAX_STYLE_BYTES) {
            return false;
        }

        $compact = strtolower((string) preg_replace('/\s+/', '', $css));
        foreach (['@', '\\', '/*', 'expression', 'behavior', 'javascript', 'vbscript', 'data:', '-moz-binding'] as $fragment) {
            if (str_contains($compact, $fragment)) {
                return false;
            }
        }

        return !$this->hasExternalUrl($css);
    }

    // ---------------------------------------------------------------- les `use`

    /**
     * Retire les `use` sans cible dans le fichier, ou dont la cible contient elle-même un `use` (ou est un `use`) : jamais de chaîne de références, donc jamais
     * d'explosion exponentielle quand un navigateur dessine le fichier.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private function withoutUnsafeUses(array $tree): array
    {
        $byId = [];
        $this->collectIds($tree, $byId);

        return $this->prune($tree, $byId);
    }

    /**
     * @param array<string, mixed>                $node
     * @param array<string, array<string, mixed>> $byId
     */
    private function collectIds(array $node, array &$byId): void
    {
        if (isset($node['attrs']['id'])) {
            $byId[(string) $node['attrs']['id']] = $node;
        }
        foreach ($node['children'] as $child) {
            $this->collectIds($child, $byId);
        }
    }

    /**
     * @param array<string, mixed>                $node
     * @param array<string, array<string, mixed>> $byId
     *
     * @return array<string, mixed>
     */
    private function prune(array $node, array $byId): array
    {
        $children = [];
        foreach ($node['children'] as $child) {
            if ($child['name'] === 'use') {
                $target = $byId[ltrim($child['attrs']['href'] ?? '', '#')] ?? null;
                if ($target === null || $target['name'] === 'use' || $this->containsUse($target)) {
                    continue;
                }
            }
            $children[] = $this->prune($child, $byId);
        }
        $node['children'] = $children;

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function containsUse(array $node): bool
    {
        foreach ($node['children'] as $child) {
            if ($child['name'] === 'use' || $this->containsUse($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasShape(array $node): bool
    {
        foreach ($node['children'] as $child) {
            if (in_array($child['name'], self::SHAPES, true) || $this->hasShape($child)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- la taille

    /**
     * La taille exploitable de la racine : son `viewBox`, à défaut sa largeur et sa hauteur en unités (jamais un pourcentage).
     *
     * @param array<string, mixed> $root
     *
     * @return array{int, int}|null
     */
    private function size(array $root): ?array
    {
        $number = '[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?';
        if (isset($root['attrs']['viewBox']) && preg_match('/^\s*(' . $number . ')[\s,]+(' . $number . ')[\s,]+(' . $number . ')[\s,]+(' . $number . ')\s*$/', $root['attrs']['viewBox'], $box) === 1) {
            $width = (float) $box[3];
            $height = (float) $box[4];
            if ($width > 0 && $height > 0 && is_finite($width) && is_finite($height)) {
                return [$this->pixels($width), $this->pixels($height)];
            }
        }

        $width = $this->length($root['attrs']['width'] ?? null);
        $height = $this->length($root['attrs']['height'] ?? null);

        return $width !== null && $height !== null ? [$this->pixels($width), $this->pixels($height)] : null;
    }

    private function length(?string $value): ?float
    {
        if ($value === null || preg_match('/^\s*(\d+\.?\d*|\.\d+)\s*(?:px|pt|pc|mm|cm|in|em|ex)?\s*$/i', $value, $match) !== 1) {
            return null;
        }
        $length = (float) $match[1];

        return $length > 0 && $length <= self::MAX_SIDE ? $length : null;
    }

    private function pixels(float $value): int
    {
        return max(1, min(1_000_000, (int) round($value)));
    }

    // ---------------------------------------------------------------- la sortie

    /**
     * @param array<string, mixed> $node
     */
    private function write(XMLWriter $writer, array $node, bool $isRoot): void
    {
        $writer->startElement($node['name']);
        if ($isRoot) {
            $writer->writeAttribute('xmlns', self::SVG_NAMESPACE);
        }
        foreach ($node['attrs'] as $name => $value) {
            $writer->writeAttribute($name, $value);
        }
        if ($node['text'] !== '') {
            $writer->text($node['text']);
        }
        foreach ($node['children'] as $child) {
            $this->write($writer, $child, false);
        }
        $writer->endElement();
    }
}
