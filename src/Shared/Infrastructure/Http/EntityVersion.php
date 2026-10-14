<?php

namespace Repas\Shared\Infrastructure\Http;


use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Version d'une ressource dans les en-têtes HTTP (ADR 0016) : `ETag: "3"` à la lecture, `If-Match: "3"` à la
 * modification. Le controller ne fait que traduire l'en-tête en entier, le contrôle est dans le domaine.
 */
final class EntityVersion
{
    public static function etag(int $version): string
    {
        return sprintf('"%d"', $version);
    }

    /**
     * Version que le client dit avoir lue : `null` sans en-tête `If-Match` (pas de contrôle) ou avec `*` (la ressource
     * existe, elle est chargée juste après).
     *
     * @throws PreconditionFailedHttpException une valeur qui n'est pas un ETag de cette API ne peut correspondre à aucune version
     */
    public static function expected(Request $request): ?int
    {
        $header = $request->headers->get('If-Match');
        if ($header === null || trim($header) === '*') {
            return null;
        }

        if (preg_match('/^"(\d{1,9})"$/', trim($header), $matches) !== 1) {
            throw new PreconditionFailedHttpException('If-Match must be an ETag returned by this API.');
        }

        return (int) $matches[1];
    }
}
