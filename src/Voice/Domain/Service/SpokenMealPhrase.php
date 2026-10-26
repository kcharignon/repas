<?php

namespace Repas\Voice\Domain\Service;

use Repas\Shared\Domain\Tool\StringTool;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\SpokenMealRequest;

/**
 * Ce que l'utilisateur dit d'un REPAS, c'est-à-dire d'une recette (ADR 0029 décision 13, VOX-305). Pur.
 *
 * - `requestOf()` : la demande est-elle une demande de repas ? Oui pour `AddMealIntent` (« planifie le repas hachis parmentier ») et pour
 *   `AddItemIntent` dont la phrase commence par « repas » ou « recette » (« ajoute le repas hachis parmentier » : Amazon range parfois ce qui
 *   ressemble à « ajoute … » dans l'intention des produits ; c'est le MOT qui aiguille, pas l'intention). « Ajoute un hachis parmentier » sans ce mot
 *   reste un produit.
 * - la phrase est débarrassée du mot qui annonce le repas (« le repas », « la recette de ») et du nombre de personnes dit en queue (« pour six
 *   personnes », « pour 6 ») : il reste le nom de la recette. Le créneau `servings` d'Alexa, s'il est rempli, passe avant la queue de la phrase.
 * - `servingsAnswer()`, `isKeepAnswer()` et `kindOf()` comprennent les réponses brèves du dialogue (« six », « pour six personnes », « rien », « une
 *   recette », « un produit »), quelle que soit l'intention qu'Alexa a choisie.
 */
final readonly class SpokenMealPhrase
{
    /** « le repas », « la recette de », « une recette du » en tête de phrase. */
    private const string MARKER = "/^(?:(?:le|la|l'|un|une|ce|cette|mon|ma)\\s+)?(?:repas|recettes?)(?![\\p{L}])\\s*(?:(?:de la|de l'|de|du|des|d')\\s*)?/u";

    /** « pour six personnes », « pour 6 » en fin de phrase. */
    private const string TAIL = '/\\s+pour\\s+(?<number>\\d{1,2}|\\p{L}+)(?:\\s+(?:personnes?|gens|convives|couverts?|invités))?\\s*$/u';

    /** Le nombre de personnes seul, comme réponse : « six », « 6 personnes », « pour six ». */
    private const string BARE = '/^(?:pour\\s+)?(?<number>\\d{1,2}|\\p{L}+)(?:\\s+(?:personnes?|gens|convives|couverts?|invités))?$/u';

    /** Les nombres de personnes dits en lettres (« un » et « une » comptent ici : « pour une personne »). */
    private const array NUMBER_WORDS = ['un' => 1, 'une' => 1] + SpokenIngredientNamer::NUMBER_WORDS;

    /** Les façons de dire « ne change rien », une fois passées par `StringTool::slugify`. */
    private const array KEEP_FORMS = [
        'rien', 'ne-change-rien', 'ne-rien-changer', 'on-ne-change-rien', 'laisse-comme-ca', 'laisse-tel-quel', 'pareil', 'tel-quel', 'comme-ca',
        'ne-fais-rien', 'aucun-changement', 'laisse', 'ne-change-pas', 'on-laisse-comme-ca', 'on-laisse-tel-quel', 'je-ne-change-rien', 'on-ne-fait-rien', 'rien-du-tout',
    ];

    /** Les mots qui nomment une recette ou un repas, un produit ; et ceux qui peuvent les entourer dans la réponse (« c'est une recette de cuisine »). */
    private const array MEAL_WORDS = ['recette', 'recettes', 'repas'];
    private const array ITEM_WORDS = ['produit', 'produits', 'article', 'articles', 'ingredient', 'ingredients'];
    private const array KIND_FILLERS = ['un', 'une', 'le', 'la', 'les', 'c', 'est', 'cest', 'ce', 'sont', 'plutot', 'de', 'cuisine', 'mon', 'ma', 'ca', 'des'];

    /**
     * La demande de repas que cette requête exprime, `null` si ce n'en est pas une (un produit à ajouter, une autre intention).
     */
    public function requestOf(AlexaRequest $request): ?SpokenMealRequest
    {
        return match ($request->intent()) {
            AlexaIntent::ADD_MEAL => $this->parse($request->recipePhrase ?? '', $request->servings),
            AlexaIntent::ADD_ITEM => $request->phrase !== null && $this->announcesMeal($request->phrase) ? $this->parse($request->phrase, $request->servings) : null,
            default => null,
        };
    }

    /** La phrase commence par « repas » ou « recette » (avec ou sans article) : c'est un repas, pas un produit. */
    public function announcesMeal(string $phrase): bool
    {
        return preg_match(self::MARKER, $this->lower($phrase)) === 1;
    }

    /**
     * Le nom de la recette et le nombre de personnes d'une phrase.
     *
     * @param ?int $servings le nombre de personnes du créneau `servings`, qui passe avant celui de la fin de phrase
     */
    public function parse(string $phrase, ?int $servings = null): SpokenMealRequest
    {
        $text = $this->lower($phrase);

        if (preg_match(self::TAIL, $text, $matches) === 1) {
            $said = $this->numberOf($matches['number']);
            if ($said !== null) {
                $servings ??= $said;
                $text = trim((string) preg_replace(self::TAIL, '', $text, 1));
            }
        }

        $text = trim((string) preg_replace(self::MARKER, '', $text, 1));

        return new SpokenMealRequest($text, $servings);
    }

    /**
     * Le nouveau total de personnes dit comme réponse (« 6 », « six personnes », « pour six »), de 1 à 99 ; `null` pour tout autre texte.
     */
    public function servingsAnswer(string $text): ?int
    {
        if (preg_match(self::BARE, $this->lower($text), $matches) !== 1) {
            return null;
        }

        return $this->numberOf($matches['number']);
    }

    /** « rien », « ne change rien », « laisse comme ça » : le repas déjà planifié reste tel quel. */
    public function isKeepAnswer(string $text): bool
    {
        return in_array(StringTool::slugify($text), self::KEEP_FORMS, true);
    }

    /**
     * « une recette », « c'est un repas » donnent `meal` ; « un produit », « c'est un article » donnent `item` ; tout autre texte, `null`. Le texte ne
     * doit contenir QUE ces mots et des mots de liaison : une recette qui s'appelle « repas de Noël » n'est pas une réponse.
     *
     * @return ?string `meal`, `item` ou `null`
     */
    public function kindOf(string $text): ?string
    {
        $words = array_values(array_filter(explode('-', StringTool::slugify($text)), static fn(string $word): bool => $word !== ''));
        if ($words === []) {
            return null;
        }

        $kind = null;
        foreach ($words as $word) {
            if (in_array($word, self::MEAL_WORDS, true)) {
                $kind = $kind === 'item' ? 'mixed' : 'meal';
            } elseif (in_array($word, self::ITEM_WORDS, true)) {
                $kind = $kind === 'meal' ? 'mixed' : 'item';
            } elseif (!in_array($word, self::KIND_FILLERS, true)) {
                return null;
            }
        }

        return $kind === 'mixed' ? null : $kind;
    }

    private function numberOf(string $number): ?int
    {
        $value = ctype_digit($number) ? (int) $number : (self::NUMBER_WORDS[$number] ?? null);

        return $value !== null && $value >= 1 && $value <= 99 ? $value : null;
    }

    private function lower(string $phrase): string
    {
        return trim((string) preg_replace('/\\s+/u', ' ', mb_strtolower(str_replace('’', "'", $phrase))));
    }
}
