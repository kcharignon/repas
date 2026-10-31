<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\ActiveListLineDTO;
use Repas\Repas\Application\OpenHostService\ActiveListLineLookupQueryOHS;
use Repas\Voice\Application\Port\ListLineLookupQueryPort;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Model\SpokenListLookupResult;

/**
 * Couche anticorruption (ADR 0035) : ce que la liste active de `Repas` publie pour une phrase (une ligne, plusieurs qui se valent, aucune, pas de
 * liste) devient ce que la réponse vocale attend. Rien n'est décidé ici.
 */
final readonly class ListLineLookupQueryAdapter implements ListLineLookupQueryPort
{
    public function __construct(
        private ActiveListLineLookupQueryOHS $lines,
    ) {
    }

    public function find(string $userId, string $phrase): SpokenListLookupResult
    {
        $match = $this->lines->findLine($userId, $phrase);

        if (!$match->hasList) {
            return $match->listSent ? SpokenListLookupResult::sentList() : SpokenListLookupResult::noList();
        }
        if ($match->lines === []) {
            return SpokenListLookupResult::notInList($match->absentName, array_map(self::line(...), $match->suggestions));
        }

        return count($match->lines) > 1
            ? SpokenListLookupResult::tied(array_map(self::line(...), $match->lines))
            : SpokenListLookupResult::found(self::line($match->lines[0]));
    }

    private static function line(ActiveListLineDTO $line): ListedLine
    {
        return new ListedLine($line->slug, $line->name, $line->departmentSlug, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName);
    }
}
