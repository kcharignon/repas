<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Dto\SpokenListLookupResult;
use Repas\Shared\Application\Interface\SpokenListLookup;

/**
 * Adaptateur du port `SpokenListLookup` (ADR 0029 décision 12, VOX-304) : lit les lignes de la liste active, puis laisse le MÊME classeur que
 * pour l'ajout (`SpokenIngredientRanker`, avec la mémoire de phrase) désigner l'ingrédient parmi tous ceux de l'utilisateur ; rien n'est écrit.
 *
 * - le classeur ne retient rien : « pas dans la liste », sans nom (jamais de proposition, jamais de création) ;
 * - sinon `SpokenIngredientRanker::equivalents()` rend TOUS les ingrédients qui valent la phrase par leur nom, quelle que soit la confiance du
 *   classeur : les habitudes ne doivent pas faire oublier celui qui est dans la liste (« tomate » en fruit et légume, 40 usages, et en surgelé, 2
 *   usages, dont seule la surgelée est dans la liste). Ceux qui sont DANS la liste passent avant : aucun, le produit n'y est pas (avec le nom de
 *   l'ingrédient que le classeur aurait choisi) ; un seul, c'est lui ; plusieurs, ils sont rendus à départager (trois au plus) ;
 * - deux lignes de même nom ET de même rayon (des doublons que l'utilisateur n'a pas fusionnés) ne se départagent pas par une question : on garde
 *   celle qui a la plus grande quantité (la fusion des doublons règle le fond, `docs/ingredients/fusion.md`) ;
 * - sans liste active : la liste envoyée au drive, si elle existe, se dit à part (elle n'est ni lue ni modifiée), sinon « pas de liste ».
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
            return $this->lines->hasSentList($userId) ? SpokenListLookupResult::sentList() : SpokenListLookupResult::noList();
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

        /** @var list<ListedLine> $present */
        $present = [];
        foreach ($this->ranker->equivalents($phrase, $candidates, $remembered) as $equivalent) {
            if (isset($inList[$equivalent->slug])) {
                $present[] = $inList[$equivalent->slug];
            }
        }
        $present = self::withoutDuplicates($present);

        return match (count($present)) {
            0 => SpokenListLookupResult::notInList($ranked->name),
            1 => SpokenListLookupResult::found($present[0]),
            default => SpokenListLookupResult::tied(array_slice($present, 0, SpokenIngredientRanker::MAX_SUGGESTIONS)),
        };
    }

    /**
     * Une seule ligne par nom ET rayon : la plus grande quantité (la première en cas d'égalité), dans l'ordre où les noms sont apparus.
     *
     * @param list<ListedLine> $lines
     *
     * @return list<ListedLine>
     */
    private static function withoutDuplicates(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            $key = $line->name . '|' . $line->departmentSlug;
            if (!isset($kept[$key]) || $line->quantity > $kept[$key]->quantity) {
                $kept[$key] = $line;
            }
        }

        return array_values($kept);
    }
}
