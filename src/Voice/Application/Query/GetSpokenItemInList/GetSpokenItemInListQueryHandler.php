<?php

namespace Repas\Voice\Application\Query\GetSpokenItemInList;


use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Interface\SpokenListLookup;
use Repas\Voice\Domain\Model\ListLine;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Répond à « est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : une lecture, par `SpokenListLookup`, qui ne modifie
 * rien et ne crée jamais de liste. La phrase du créneau `item` garde un éventuel nombre ou unité en tête (« deux paquets de croquettes ») : ils sont
 * retirés comme pour l'ajout, la consultation ne dit pas combien chercher mais combien il y en a.
 *
 * Quatre réponses : pas de liste active (« Tu n'as pas de liste en cours. »), produit absent ou inconnu (« Non, il n'y a pas de croquette dans ta
 * liste. »), une ligne (« Oui, tu en as 2 paquets. »), plusieurs lignes qui se valent (« Oui : 2 tomates en fruit et légume, 1 en surgelé. »).
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSpokenItemInListQueryHandler
{
    public function __construct(
        private SpokenListLookup $lookup,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(GetSpokenItemInListQuery $query): SpokenItemInListDTO
    {
        $phrase = $this->namer->splitSpoken($query->phrase)['phrase'];

        $found = $this->lookup->find($query->userId, $phrase);

        return new SpokenItemInListDTO($this->speech($found->hasList, $found->lines, $found->absentName, $phrase), $query->sessionIsOpen);
    }

    /**
     * @param list<ListedLine> $lines
     */
    private function speech(bool $hasList, array $lines, ?string $absentName, string $phrase): string
    {
        if (!$hasList) {
            return VoiceReplies::NO_LIST;
        }
        if ($lines === []) {
            $name = $absentName ?? $this->namer->nameFrom($phrase);

            return $name === null ? VoiceReplies::NOT_UNDERSTOOD : VoiceReplies::notInListAnswer($name);
        }

        $listed = array_map(
            static fn(ListedLine $line): ListLine => new ListLine($line->name, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName),
            $lines,
        );

        return count($listed) === 1 ? VoiceReplies::inList($listed[0]) : VoiceReplies::inListMany($listed);
    }
}
