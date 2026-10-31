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

        return self::found($match->isTied(), array_map(self::line(...), $match->lines));
    }

    /**
     * Plusieurs produits qui se valent : à départager. Un seul produit : une ligne par liste en cours qui le porte (ADR 0036, LST-008).
     *
     * @param non-empty-list<ListedLine> $lines
     */
    private static function found(bool $tied, array $lines): SpokenListLookupResult
    {
        return match (true) {
            $tied => SpokenListLookupResult::tied($lines),
            count($lines) === 1 => SpokenListLookupResult::found($lines[0]),
            default => SpokenListLookupResult::foundIn($lines),
        };
    }

    private static function line(ActiveListLineDTO $line): ListedLine
    {
        return new ListedLine($line->slug, $line->name, $line->departmentSlug, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName, $line->listName);
    }
}
