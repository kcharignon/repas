<?php

namespace Repas\Voice\Domain\Service;

use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Model\ListLine;
use Repas\Voice\Domain\Model\SpokenListLookupResult;
use Repas\Voice\Domain\Model\VoiceReplies;

/**
 * La réponse parlée à « est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : ce que la liste active contient pour la
 * phrase (`ListLineLookupQueryPort` ou `ListLineLookupCommandPort`, lu par l'appelant), mis en mots. Pur : il ne lit rien et n'écrit rien. La phrase du créneau `item` garde un
 * éventuel nombre ou unité en tête (« deux paquets de croquettes ») : `phraseToLookUp()` les retire comme pour l'ajout
 * (`SpokenIngredientNamer::splitSpoken`), la consultation ne dit pas combien chercher mais combien il y en a.
 *
 * Cinq réponses : pas de liste active (« Tu n'as pas de liste en cours. »), la seule liste est partie au drive (« Ta liste est déjà partie au drive. »),
 * produit absent ou inconnu (« Non, il n'y a pas de croquette dans ta liste. »), une ligne (« Oui, tu en as 2 paquets. »), plusieurs lignes qui se
 * valent (« Oui : 2 tomates en fruit et légume, 1 en surgelé. »). Partagée par la query `GetSpokenItemInList` et par le dialogue d'une question en cours
 * (`AnswerSpokenDialogueHandler`, une consultation dite au milieu d'une question).
 */
final readonly class SpokenListAnswerer
{
    public function __construct(
        private SpokenIngredientNamer $namer,
    ) {
    }

    /** La phrase à chercher dans la liste : celle qu'Alexa a entendue, sans le nombre ni l'unité laissés en tête. */
    public function phraseToLookUp(string $phrase): string
    {
        return $this->namer->splitSpoken($phrase)['phrase'];
    }

    /**
     * @param string $phrase celle que `phraseToLookUp()` a rendue et dont la liste a été lue
     */
    public function speech(SpokenListLookupResult $found, string $phrase): string
    {
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
