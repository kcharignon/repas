<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Application\Loader\PlannedMealsInActiveList;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Application\Loader\SpokenRecipePhraseMemory;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Dto\ListedMeal;
use Repas\Shared\Application\Dto\SpokenListLookupResult;
use Repas\Shared\Application\Dto\SpokenMealLookupResult;
use Repas\Shared\Application\Interface\SpokenListLookup;
use Repas\Shared\Domain\Tool\StringTool;

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
 * - sans liste active : la liste envoyée au drive, si elle existe, se dit à part (elle n'est ni lue ni modifiée), sinon « pas de liste » ;
 * - une phrase qui ne désigne AUCUN ingrédient de l'utilisateur reçoit en plus les lignes de la liste qui lui ressemblent (`suggest`, trois au plus, un
 *   nom une fois) : Alexa propose d'en retirer une (VOX-306). Un ingrédient reconnu mais absent de la liste n'en reçoit pas.
 *
 * `findMeal()` (VOX-306) cherche de même un REPAS : parmi les recettes planifiées de la liste active seulement (jamais les autres recettes de
 * l'utilisateur), avec le classeur de noms des produits et la mémoire de phrase des recettes. Un repas incertain (deux noms qui se touchent) est
 * proposé avec les autres, jamais deviné.
 */
final readonly class SpokenListLookupAdapter implements SpokenListLookup
{
    private SpokenIngredientRanker $ranker;

    public function __construct(
        private ActiveShoppingListLines $lines,
        private SpokenIngredientCandidates $candidates,
        private SpokenPhraseMemory $memory,
        private PlannedMealsInActiveList $meals,
        private SpokenRecipePhraseMemory $recipeMemory,
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
            return SpokenListLookupResult::notInList(null, $this->nearLines($phrase, $candidates, $inList));
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

    public function findMeal(string $userId, string $phrase): SpokenMealLookupResult
    {
        $planned = $this->meals->forUser($userId);
        if ($planned === null) {
            return $this->lines->hasSentList($userId) ? SpokenMealLookupResult::sentList() : SpokenMealLookupResult::noList();
        }

        $byId = [];
        $candidates = [];
        foreach ($planned as $meal) {
            $byId[$meal->recipeId] = $meal;
            $candidates[] = new SpokenCandidate($meal->recipeId, StringTool::slugify($meal->name), $meal->name, '');
        }
        if ($candidates === []) {
            return SpokenMealLookupResult::notPlanned();
        }

        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' ? null : $this->recipeMemory->rememberedId($userId, $key);

        $ranked = $this->ranker->rank($phrase, $candidates, rememberedSlug: $remembered);
        if ($ranked === null) {
            return SpokenMealLookupResult::notPlanned(self::asMeals($this->ranker->suggest($phrase, $candidates), $byId));
        }
        if ($ranked->confidence === Confidence::UNSURE) {
            $ties = $this->ranker->ties($phrase, $candidates, $remembered);
            if ($ties !== []) {
                return SpokenMealLookupResult::notPlanned(self::asMeals($ties, $byId));
            }
        }

        return SpokenMealLookupResult::found($byId[$ranked->slug]);
    }

    /**
     * Les lignes de la liste qui ressemblent à une phrase qui ne désigne aucun ingrédient : les propositions du classeur, restreintes aux ingrédients
     * DANS la liste, un nom une fois (deux lignes du même nom, dans deux rayons, ne se proposent pas par la voix).
     *
     * @param list<SpokenCandidate> $candidates
     * @param array<string, ListedLine> $inList
     *
     * @return list<ListedLine>
     */
    private function nearLines(string $phrase, array $candidates, array $inList): array
    {
        $present = array_values(array_filter($candidates, static fn(SpokenCandidate $candidate): bool => isset($inList[$candidate->slug])));
        if ($present === []) {
            return [];
        }

        $near = [];
        foreach ($this->ranker->suggest($phrase, $present) as $suggestion) {
            $line = $inList[$suggestion->slug];
            $near[$line->name] ??= $line;
        }

        return array_values($near);
    }

    /**
     * @param list<RankedIngredient> $ranked
     * @param array<string, ListedMeal> $byId
     *
     * @return list<ListedMeal>
     */
    private static function asMeals(array $ranked, array $byId): array
    {
        return array_map(static fn(RankedIngredient $item): ListedMeal => $byId[$item->slug], $ranked);
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
