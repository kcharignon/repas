<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\ActiveListLineLookupCommandOHS;
use Repas\Repas\Domain\Model\ActiveListLine;
use Repas\Voice\Application\Port\ListLineLookupCommandPort;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Model\SpokenListLookupResult;

/**
 * Couche anticorruption (ADR 0035) : ce que la liste active de `Repas` contient pour une phrase (une ligne, plusieurs qui se valent, aucune, pas
 * de liste) devient ce que le dialogue vocal attend. Rien n'est décidé ici.
 */
final readonly class ListLineLookupCommandAdapter implements ListLineLookupCommandPort
{
    public function __construct(
        private ActiveListLineLookupCommandOHS $lines,
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

        return $match->isTied()
            ? SpokenListLookupResult::tied(array_map(self::line(...), $match->lines))
            : SpokenListLookupResult::found(self::line($match->lines[0]));
    }

    private static function line(ActiveListLine $line): ListedLine
    {
        return new ListedLine($line->slug, $line->name, $line->departmentSlug, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName);
    }
}
