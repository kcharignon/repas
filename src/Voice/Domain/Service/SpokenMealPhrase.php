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
 *   personnes », « pour 6 », « pour trente », « pour 100 personnes ») : il reste le nom de la recette. Le créneau `servings` d'Alexa, s'il est rempli,
 *   passe avant la queue de la phrase. Tout nombre LU par `SpokenNumber` est retiré du nom ; hors de 1 à 99 il n'est pas gardé et la demande le dit
 *   (`SpokenMealRequest::$unusableServings`) pour qu'Alexa redemande combien. La phrase d'origine (le mot gardé) reste dans `SpokenMealRequest::$original`.
 * - `servingsAnswer()`, `isKeepAnswer()` et `kindOf()` comprennent les réponses brèves du dialogue (« six », « rien », « une recette », « un produit »),
 *   quelle que soit l'intention qu'Alexa a choisie.
 */
final readonly class SpokenMealPhrase
{
    /** « le repas », « la recette de », « une recette du » en tête de phrase. */
    private const string MARKER = "/^(?:(?:le|la|l'|un|une|ce|cette|mon|ma)\\s+)?(?:repas|recettes?)(?![\\p{L}])\\s*(?:(?:de la|de l'|de|du|des|d')\\s*)?/u";

    /** « pour six personnes » : ce qui suit le dernier « pour » est un nombre, suivi ou non d'un mot de personnes. */
    private const string TAIL = '/^(?<head>.*)\\s+pour\\s+(?<tail>\\S.*)$/u';

    /** Le mot qui peut suivre le nombre de personnes. */
    private const string PEOPLE = '/\\s+(?:personnes?|gens|convives|couverts?|invités)$/u';

    /** Les façons de dire « ne change rien », une fois passées par `StringTool::slugify`. */
    private const array KEEP_FORMS = [
        'rien', 'ne-change-rien', 'ne-rien-changer', 'on-ne-change-rien', 'laisse-comme-ca', 'laisse-tel-quel', 'pareil', 'tel-quel', 'comme-ca',
        'ne-fais-rien', 'aucun-changement', 'laisse', 'ne-change-pas', 'on-laisse-comme-ca', 'on-laisse-tel-quel', 'je-ne-change-rien', 'on-ne-fait-rien',
        'rien-du-tout', 'non', 'non-merci',
    ];

    /** Les mots qui nomment une recette ou un repas, un produit ; et ceux qui peuvent les entourer dans la réponse (« c'est une recette de cuisine »). */
    private const array MEAL_WORDS = ['recette', 'recettes', 'repas'];
    private const array ITEM_WORDS = ['produit', 'produits', 'article', 'articles', 'ingredient', 'ingredients'];
    private const array KIND_FILLERS = ['un', 'une', 'le', 'la', 'les', 'c', 'est', 'cest', 'ce', 'sont', 'plutot', 'de', 'cuisine', 'mon', 'ma', 'ca', 'des'];

    private SpokenNumber $numbers;

    public function __construct(?SpokenNumber $numbers = null)
    {
        $this->numbers = $numbers ?? new SpokenNumber();
    }

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
        $unusable = false;

        if (preg_match(self::TAIL, $text, $matches) === 1) {
            $said = $this->numbers->parse((string) preg_replace(self::PEOPLE, '', $matches['tail']));
            if ($said !== null) {
                $text = trim($matches['head']);
                if ($servings === null) {
                    $servings = self::isUsable($said) ? $said : null;
                    $unusable = !self::isUsable($said);
                }
            }
        }

        $original = $text;
        $text = trim((string) preg_replace(self::MARKER, '', $text, 1));

        return new SpokenMealRequest($text, $servings, $unusable, $original);
    }

    /**
     * Le nouveau total de personnes dit comme réponse (« 6 », « six personnes », « pour six »), de 1 à 99 ; `null` pour tout autre texte, y compris
     * un nombre hors de cet intervalle (`numberSaid()` le reconnaît).
     */
    public function servingsAnswer(string $text): ?int
    {
        $number = $this->numberSaid($text);

        return $number !== null && self::isUsable($number) ? $number : null;
    }

    /**
     * Le nombre que ce texte dit à lui seul (« 6 », « six personnes », « pour six », « 0 », « 100 », « cent »), utilisable ou non ; `null` si ce n'est pas
     * qu'un nombre. Sert à ne pas prendre « cent » pour un produit quand on attend un nombre de personnes.
     */
    public function numberSaid(string $text): ?int
    {
        $bare = (string) preg_replace(self::PEOPLE, '', (string) preg_replace('/^pour\\s+/u', '', $this->lower($text)));

        return $this->numbers->parse($bare);
    }

    /** « rien », « ne change rien », « laisse comme ça », « non » : le repas déjà planifié reste tel quel. */
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

    /** Un nombre de personnes se dit de 1 à 99, comme une quantité. */
    private static function isUsable(int $number): bool
    {
        return $number >= 1 && $number <= 99;
    }

    private function lower(string $phrase): string
    {
        return trim((string) preg_replace('/\\s+/u', ' ', mb_strtolower(str_replace('’', "'", $phrase))));
    }
}
