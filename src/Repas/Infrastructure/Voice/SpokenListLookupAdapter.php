<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Dto\SpokenListLookupResult;
use Repas\Shared\Application\Interface\SpokenListLookup;

/**
 * Adaptateur du port `SpokenListLookup` (ADR 0029 décision 12, VOX-304) : lit les lignes de la liste active, puis laisse le MÊME classeur que
 * pour l'ajout (`SpokenIngredientRanker`, avec la mémoire de phrase) désigner l'ingrédient parmi tous ceux de l'utilisateur ; rien n'est écrit.
 *
 * - le classeur n'hésite pas : son ingrédient est dans la liste ou non ;
 * - il hésite entre des ingrédients qui se valent (« tomate » en fruit et légume et en surgelé) : ceux qui sont DANS la liste passent avant. Un
 *   seul dans la liste, c'est lui ; plusieurs, ils sont rendus à départager ; aucun, le produit n'y est pas ;
 * - rien ne ressemble à la phrase : « pas dans la liste », sans nom (jamais de proposition, jamais de création).
 */
final readonly class SpokenListLookupAdapter implements SpokenListLookup
{
    private SpokenIngredientRanker $ranker;

    public function __construct(
        private ActiveShoppingListLines $lines,
        private SpokenIngredientCandidates $candidates,
        private SpokenPhraseMemory $memory,
    ) {
        $this->ranker = new SpokenIngredientRanker();
    }

    public function find(string $userId, string $phrase): SpokenListLookupResult
    {
        $lines = $this->lines->forUser($userId);
        if ($lines === null) {
            return SpokenListLookupResult::noList();
        }

        $inList = [];
        foreach ($lines as $line) {
            $inList[$line->slug] = $line;
        }

        $candidates = $this->candidates->forUser($userId);
        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' || $candidates === [] ? null : $this->memory->rememberedSlug($userId, $key);

        $ranked = $candidates === [] ? null : $this->ranker->rank($phrase, $candidates, rememberedSlug: $remembered);
        if ($ranked === null) {
            return SpokenListLookupResult::notInList(null);
        }

        if ($ranked->confidence === Confidence::UNSURE) {
            $ties = $this->ranker->ties($phrase, $candidates, $remembered);
            if ($ties !== []) {
                /** @var list<ListedLine> $present */
                $present = [];
                foreach ($ties as $tie) {
                    if (isset($inList[$tie->slug])) {
                        $present[] = $inList[$tie->slug];
                    }
                }

                return match (count($present)) {
                    0 => SpokenListLookupResult::notInList($ranked->name),
                    1 => SpokenListLookupResult::found($present[0]),
                    default => SpokenListLookupResult::tied($present),
                };
            }
        }

        return isset($inList[$ranked->slug]) ? SpokenListLookupResult::found($inList[$ranked->slug]) : SpokenListLookupResult::notInList($ranked->name);
    }
}
