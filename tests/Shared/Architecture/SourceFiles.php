<?php

namespace Repas\Tests\Shared\Architecture;

use Generator;

/**
 * Le code de `src/` vu par les garde-fous d'architecture (ADR 0035) : les fichiers de chaque contexte, sans les fixtures de développement
 * (elles composent les contextes) ni les migrations (elles portent l'histoire du schéma).
 */
final class SourceFiles
{
    public const array CONTEXTS = ['Repas', 'User', 'Drive', 'Voice'];

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return Generator<string, string> chemin relatif à la racine du dépôt => contenu
     */
    public static function all(): Generator
    {
        $root = self::root();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        $paths = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        foreach ($paths as $path) {
            $relative = substr($path, strlen($root) + 1);
            if (str_contains($relative, '/DataFixture/')) {
                continue;
            }
            yield $relative => (string) file_get_contents($path);
        }
    }

    /**
     * Les fichiers d'un contexte, dont le chemin passe l'expression régulière.
     *
     * @return Generator<string, string>
     */
    public static function matching(string $pattern): Generator
    {
        foreach (self::all() as $path => $source) {
            if (preg_match($pattern, $path) === 1) {
                yield $path => $source;
            }
        }
    }

    /** Le contexte d'un fichier de `src/` (`src/Repas/…` => `Repas`), ou `null` pour `Shared` et le reste. */
    public static function contextOf(string $path): ?string
    {
        return preg_match('#^src/(Repas|User|Drive|Voice)/#', $path, $matches) === 1 ? $matches[1] : null;
    }

    /** Le code sans ses commentaires (les mots « FROM » ou « use » d'une phrase ne sont pas du code). */
    public static function code(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** Les classes importées par les `use` d'un fichier. @return list<string> */
    public static function imports(string $source): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $source, $matches);

        return $matches[1];
    }
}
