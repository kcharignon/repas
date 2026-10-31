<?php

namespace Repas\Repas\Domain\Service;

use Closure;
use Repas\Repas\Domain\Model\ActiveListLine;
use Repas\Repas\Domain\Model\ActiveListLineMatch;
use Repas\Repas\Domain\Model\ActiveListMeal;
use Repas\Repas\Domain\Model\ActiveListMealMatch;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * Cherche, dans le contenu de la liste de courses active d'un utilisateur, ce qu'une phrase désigne (ADR 0029 décision 12, VOX-304 et VOX-306).
 * Pur : ni base ni horloge, l'appelant lit la liste, les ingrédients de l'utilisateur et sa mémoire de phrase (que cette classe ne lit que par la
 * fermeture qu'on lui donne, et seulement quand elle en a besoin). Rien n'est écrit et aucune liste n'est créée.
 *
 * `line()` laisse le MÊME classeur que pour l'ajout (`SpokenIngredientRanker`, avec la mémoire de phrase) désigner l'ingrédient parmi tous ceux de
 * l'utilisateur :
 * - le classeur ne retient rien : « pas dans la liste », sans nom (jamais de proposition, jamais de création) ;
 * - sinon `SpokenIngredientRanker::equivalents()` rend TOUS les ingrédients qui valent la phrase par leur nom, quelle que soit la confiance du
 *   classeur : les habitudes ne doivent pas faire oublier celui qui est dans la liste (« tomate » en fruit et légume, 40 usages, et en surgelé, 2
 *   usages, dont seule la surgelée est dans la liste). Ceux qui sont DANS la liste passent avant : aucun, le produit n'y est pas (avec le nom de
 *   l'ingrédient que le classeur aurait choisi) ; un seul, c'est lui ; plusieurs, ils sont rendus à départager (trois au plus) ;
 * - deux lignes de même nom ET de même rayon (des doublons que l'utilisateur n'a pas fusionnés) ne se départagent pas par une question : on garde
 *   celle qui a la plus grande quantité (la fusion des doublons règle le fond, `docs/ingredients/fusion.md`) ;
 * - une phrase qui ne désigne AUCUN ingrédient de l'utilisateur reçoit en plus les lignes de la liste qui lui ressemblent (`suggest`, trois au plus,
 *   un nom une fois) : l'appelant propose d'en retirer une (VOX-306). Un ingrédient reconnu mais absent de la liste n'en reçoit pas.
 *
 * `meal()` (VOX-306) cherche de même un REPAS : parmi les recettes planifiées de la liste active seulement (jamais les autres recettes de
 * l'utilisateur), avec le classeur de noms des produits et la mémoire de phrase des recettes. Un repas incertain (deux noms qui se touchent) est
 * proposé avec les autres, jamais deviné.
 */
final readonly class ActiveListLookup
{
    private SpokenIngredientRanker $ranker;

    public function __construct()
    {
        $this->ranker = new SpokenIngredientRanker();
    }

    /**
     * @param list<ActiveListLine>  $lines          les lignes de la liste ACTIVE
     * @param list<SpokenCandidate> $candidates     les ingrédients de l'utilisateur, avec leur habitude
     * @param Closure(string): ?string $rememberedSlug ce que l'utilisateur a déjà choisi pour la clé de cette phrase (`SpokenIngredientRanker::phraseKey`)
     */
    public function line(string $phrase, array $lines, array $candidates, Closure $rememberedSlug): ActiveListLineMatch
    {
        $inList = [];
        foreach ($lines as $line) {
            $inList[$line->slug] = $line;
        }

        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' || $candidates === [] ? null : $rememberedSlug($key);

        $ranked = $candidates === [] ? null : $this->ranker->rank($phrase, $candidates, rememberedSlug: $remembered);
        if ($ranked === null) {
            return ActiveListLineMatch::notInList(null, $this->nearLines($phrase, $candidates, $inList));
        }

        /** @var list<ActiveListLine> $present */
        $present = [];
        foreach ($this->ranker->equivalents($phrase, $candidates, $remembered) as $equivalent) {
            if (isset($inList[$equivalent->slug])) {
                $present[] = $inList[$equivalent->slug];
            }
        }
        $present = self::withoutDuplicates($present);

        return match (count($present)) {
            0 => ActiveListLineMatch::notInList($ranked->name),
            1 => ActiveListLineMatch::found($present[0]),
            default => ActiveListLineMatch::tied(array_slice($present, 0, SpokenIngredientRanker::MAX_SUGGESTIONS)),
        };
    }

    /**
     * @param list<ActiveListMeal>     $planned        les repas de la liste ACTIVE
     * @param Closure(string): ?string $rememberedId   ce que l'utilisateur a déjà choisi (l'identifiant d'une recette) pour la clé de cette phrase
     */
    public function meal(string $phrase, array $planned, Closure $rememberedId): ActiveListMealMatch
    {
        $byId = [];
        $candidates = [];
        foreach ($planned as $meal) {
            $byId[$meal->recipeId] = $meal;
            $candidates[] = new SpokenCandidate($meal->recipeId, StringTool::slugify($meal->name), $meal->name, '');
        }
        if ($candidates === []) {
            return ActiveListMealMatch::notPlanned();
        }

        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' ? null : $rememberedId($key);

        $ranked = $this->ranker->rank($phrase, $candidates, rememberedSlug: $remembered);
        if ($ranked === null) {
            return ActiveListMealMatch::notPlanned(self::asMeals($this->ranker->suggest($phrase, $candidates), $byId));
        }
        // Le choix de l'utilisateur passe avant tout, même entre deux repas de même nom
        if ($remembered === null || $ranked->slug !== $remembered) {
            if ($ranked->confidence === Confidence::UNSURE) {
                $ties = $this->ranker->ties($phrase, $candidates, $remembered);
                if ($ties !== []) {
                    return ActiveListMealMatch::notPlanned(self::asMeals($ties, $byId));
                }
            }

            // Deux repas de même nom (deux recettes « Hachis parmentier », une copie par exemple : le domaine n'interdit que la MÊME recette deux fois) : le
            // classeur ne les départage pas (même nom, aucun rayon), un repas retiré au hasard serait une erreur : ils sont proposés, avec leur nombre de personnes
            $homonyms = array_values(array_filter($candidates, static fn(SpokenCandidate $candidate): bool => $candidate->sluggedName === self::sluggedNameOf($candidates, $ranked->slug)));
            if (count($homonyms) > 1) {
                return ActiveListMealMatch::notPlanned(array_map(static fn(SpokenCandidate $candidate): ActiveListMeal => $byId[$candidate->slug], array_slice($homonyms, 0, SpokenIngredientRanker::MAX_SUGGESTIONS)));
            }
        }

        return ActiveListMealMatch::found($byId[$ranked->slug]);
    }

    /**
     * Les lignes de la liste qui ressemblent à une phrase qui ne désigne aucun ingrédient : les propositions du classeur, restreintes aux ingrédients
     * DANS la liste, un nom une fois (deux lignes du même nom, dans deux rayons, ne se proposent pas par la voix).
     *
     * @param list<SpokenCandidate> $candidates
     * @param array<string, ActiveListLine> $inList
     *
     * @return list<ActiveListLine>
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
     * @param list<SpokenCandidate> $candidates
     */
    private static function sluggedNameOf(array $candidates, string $slug): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate->slug === $slug) {
                return $candidate->sluggedName;
            }
        }

        return '';
    }

    /**
     * @param list<RankedIngredient> $ranked
     * @param array<string, ActiveListMeal> $byId
     *
     * @return list<ActiveListMeal>
     */
    private static function asMeals(array $ranked, array $byId): array
    {
        return array_map(static fn(RankedIngredient $item): ActiveListMeal => $byId[$item->slug], $ranked);
    }

    /**
     * Une seule ligne par nom ET rayon : la plus grande quantité (la première en cas d'égalité), dans l'ordre où les noms sont apparus.
     *
     * @param list<ActiveListLine> $lines
     *
     * @return list<ActiveListLine>
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
