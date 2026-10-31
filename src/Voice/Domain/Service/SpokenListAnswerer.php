<?php

namespace Repas\Voice\Application\Query\GetSpokenItemInList;


use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Interface\SpokenListLookup;
use Repas\Voice\Domain\Model\ListLine;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * La réponse parlée à « est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : une LECTURE par `SpokenListLookup`, qui ne
 * modifie rien et ne crée jamais de liste. La phrase du créneau `item` garde un éventuel nombre ou unité en tête (« deux paquets de croquettes ») :
 * ils sont retirés comme pour l'ajout (`SpokenIngredientNamer::splitSpoken`), la consultation ne dit pas combien chercher mais combien il y en a.
 *
 * Cinq réponses : pas de liste active (« Tu n'as pas de liste en cours. »), la seule liste est partie au drive (« Ta liste est déjà partie au drive. »),
 * produit absent ou inconnu (« Non, il n'y a pas de croquette dans ta liste. »), une ligne (« Oui, tu en as 2 paquets. »), plusieurs lignes qui se
 * valent (« Oui : 2 tomates en fruit et légume, 1 en surgelé. »). Partagée par la query `GetSpokenItemInList` et par le dialogue d'une question en cours
 * (`AnswerSpokenDialogueHandler`, une consultation dite au milieu d'une question).
 */
final readonly class SpokenListAnswerer
{
    public function __construct(
        private SpokenListLookup $lookup,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function speech(string $userId, string $phrase): string
    {
        $phrase = $this->namer->splitSpoken($phrase)['phrase'];
        $found = $this->lookup->find($userId, $phrase);

        if (!$found->hasList) {
            return VoiceReplies::noActiveList($found->listSent);
        }
        if ($found->lines === []) {
            $name = $found->absentName ?? $this->namer->nameFrom($phrase);

            return $name === null ? VoiceReplies::NOT_UNDERSTOOD : VoiceReplies::notInListAnswer($name);
        }

        $listed = array_map(
            static fn(ListedLine $line): ListLine => new ListLine($line->name, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName),
            $found->lines,
        );

        return count($listed) === 1 ? VoiceReplies::inList($listed[0]) : VoiceReplies::inListMany($listed);
    }
}
