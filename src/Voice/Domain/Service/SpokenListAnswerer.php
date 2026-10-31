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

        $products = $found->products();
        if (count($products) === 1) {
            // Un seul produit : une ligne par liste en cours qui le porte (la liste principale d'abord, ADR 0036 LST-008)
            $listed = array_map(self::listLine(...), $products[0]);

            return count($listed) === 1 ? VoiceReplies::inList($listed[0]) : VoiceReplies::inLists($listed);
        }

        // Des produits qui se valent (même nom, rayons différents) : une quantité par produit, toutes listes ensemble
        return VoiceReplies::inListMany(array_map(self::totalOf(...), $products));
    }

    private static function listLine(ListedLine $line): ListLine
    {
        return new ListLine($line->name, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName, $line->listName);
    }

    /**
     * Un produit porté par plusieurs listes, dit en un seul total quand toutes ses lignes ont la même unité (sinon la première ligne, la liste
     * principale d'abord) : la consultation d'un départage ne dit pas dans quelle liste chaque produit se trouve.
     *
     * @param non-empty-list<ListedLine> $lines les lignes d'UN produit
     */
    private static function totalOf(array $lines): ListLine
    {
        $first = $lines[0];
        $quantity = 0.0;
        foreach ($lines as $line) {
            if ($line->unitSlug === $first->unitSlug) {
                $quantity += $line->quantity;
            }
        }

        return new ListLine($first->name, $first->departmentName, $quantity, $first->unitSlug, $first->unitName);
    }
}
