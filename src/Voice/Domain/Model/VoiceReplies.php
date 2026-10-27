<?php

namespace Repas\Voice\Domain\Model;

use Repas\Shared\Domain\Exception\SpokenMealFailure;


/**
 * Tout ce qu'Alexa dit de la part de Repas (ADR 0029 décision 2, skill `repas-po`) : le catalogue des textes, au même endroit pour
 * qu'on les relise ensemble. La voix est celle du produit : tutoiement, une à deux phrases, 15 mots au plus par phrase, aucun
 * avertissement, aucun mot technique (« ingrédient » n'apparaît que dans « tes ingrédients »). Un proche doit pouvoir dire chaque phrase à voix
 * haute ; `VoiceRepliesTest` impose la longueur et le vocabulaire.
 *
 * Une seule exception à « deux phrases » : la question « de quoi tu parles ? » d'un produit inconnu (`ASK_WHICH`, `ASK_WHICH_AGAIN`), trois
 * phrases, parce qu'elle dit ce qui n'est pas connu, les propositions et l'issue « un nouveau produit » (ADR 0029 décision 11).
 *
 * La planification d'un repas (ADR 0029 décision 13) ajoute trois autres questions de trois phrases : l'issue « ou une recette ? » d'un produit inconnu
 * (`ASK_WHICH_OR_MEAL`, `ASK_WHICH_AGAIN_OR_MEAL`, `ASK_CREATE_OR_MEAL`) et les propositions d'une recette inconnue avec l'issue « ou un produit ? »
 * (`ASK_MEAL_WHICH_OR_ITEM`, `ASK_MEAL_WHICH_AGAIN_OR_ITEM`).
 *
 * Deux autres écarts, voulus par le propriétaire après le premier essai réel (2026-10-25) : la question de l'unité fait trois phrases (elle dit
 * aussi qu'on peut demander la liste), et la réponse à « lesquels ? » (`choices()`) lit tout le catalogue des rayons ou des unités, jusqu'à
 * 25 noms : deux phrases, dont la première, l'énumération, n'a pas de limite de mots (`VoiceRepliesTest`).
 *
 * Les modèles de phrase (`%s`) reçoivent le nom d'un ingrédient, celui d'un rayon ou la phrase entendue, toujours passés par
 * `speakable()` : bornés en longueur et débarrassés des caractères de contrôle.
 */
final class VoiceReplies
{
    /** La séance s'ouvre (« Alexa, ouvre Jarvis »), ou le nom à ajouter n'a pas été compris. */
    public const string LAUNCH = 'Que veux-tu ajouter ?';
    public const string HELP = 'Dis par exemple : ajoute du pain. Je le mets dans ta liste de courses.';
    public const string FALLBACK = 'Dis par exemple : ajoute du pain.';
    public const string GOODBYE = 'À bientôt.';
    /** Le compte n'est pas lié (jeton absent, inconnu, expiré, liaison retirée ou compte inactif : la requête ne les distingue pas). */
    public const string LINK_ACCOUNT = "Ouvre l'application Alexa pour lier ton compte Repas.";
    /** Plus de 60 requêtes en une minute pour une même liaison. */
    public const string SLOW_DOWN = 'Un instant, réessaie dans une minute.';
    public const string UNSUPPORTED_LANGUAGE = 'Je ne comprends que le français pour le moment.';
    /** Une panne inattendue : jamais le détail, jamais une trace. */
    public const string FAILURE = "Je n'ai pas pu faire ça. Réessaie dans un instant.";

    /** Une quantité que nous ne savons pas écrire (zéro, plus de 99, un décimal) : rien n'est écrit, la demande est à refaire. */
    public const string HOW_MANY = 'Combien ? Redis ta demande avec un nombre de 1 à 99.';

    /** L'article était déjà dans la liste : on l'ajoute et on dit le total (X : « 3 paquets », « 5 »). Remplace « C'est noté » seulement alors. */
    public const string TOTAL_ADDED = "C'est ajouté, tu en as maintenant %s dans ta liste.";
    /** La même, quand l'unité dite n'est pas celle de la ligne (inconnue de l'ingrédient, ou convertie) : on nomme l'unité de la ligne. */
    public const string TOTAL_ADDED_IN_UNIT = "C'est ajouté en %s, tu en as maintenant %s dans ta liste.";
    public const string REMEMBER_PROMISE = "Je m'en souviendrai.";

    /** « Enlève » dit sans produit : on ne pose pas de question ouverte (la réponse brève « du pain » serait comprise comme un ajout), on donne l'exemple. */
    public const string REMOVE_EXAMPLE = 'Dis par exemple : enlève du pain.';
    /** « Est-ce qu'il y a » dit sans produit : même règle. */
    public const string CHECK_EXAMPLE = "Dis par exemple : est-ce qu'il y a du pain dans ma liste ?";
    /** Retirer ou consulter ne crée jamais de liste : sans liste active, il n'y a rien à lire ni à retirer. */
    public const string NO_LIST = "Tu n'as pas de liste en cours.";
    /** La seule liste de l'utilisateur est partie au drive : elle n'est ni lue ni modifiée, et la phrase dit pourquoi (plutôt que « pas de liste »). */
    public const string LIST_SENT = 'Ta liste est déjà partie au drive.';
    /** Retirer un produit qui n'est pas dans la liste (X : « de croquette », « d'ail »). */
    public const string NOT_IN_LIST = "Il n'y a pas %s dans ta liste.";
    /** Consulter un produit qui n'est pas dans la liste. */
    public const string NOT_IN_LIST_NO = "Non, il n'y a pas %s dans ta liste.";
    /** La ligne disparaît : tout ce qu'elle contenait est retiré (X : ce qui a été retiré, comme « C'est noté : X »). */
    public const string REMOVED_GONE = "C'est retiré : %s. Il n'y en a plus dans ta liste.";
    /** La même, sans la seconde phrase : quand ce qui a été retiré est long à dire (« 2 cuillères à soupe de crème fraîche épaisse »), la réponse entière reste courte. */
    public const string REMOVED_GONE_SHORT = "C'est retiré : %s.";
    /** La ligne diminue : on dit ce qui reste (X : « 2 paquets », « 3 »). */
    public const string REMOVED_LEFT = "C'est retiré, il t'en reste %s dans ta liste.";
    /** La même, quand l'unité dite n'est pas celle de la ligne (inconnue de l'ingrédient, ou convertie) : on nomme l'unité de la ligne. */
    public const string REMOVED_LEFT_IN_UNIT = "C'est retiré en %s, il t'en reste %s dans ta liste.";
    /** Il refuse de départager des lignes qui se valent : rien n'est retiré. */
    public const string DECLINED_REMOVAL = "D'accord, je ne retire rien.";
    /** Consulter : le produit est dans la liste (X : « 2 paquets », « 3 »). */
    public const string IN_LIST = 'Oui, tu en as %s.';
    /** Consulter : plusieurs lignes portent le produit (X : « 2 tomates en fruit et légume, 1 en surgelé »). */
    public const string IN_LIST_MANY = 'Oui : %s.';

    /** Le nom entendu est celui de l'ingrédient. */
    public const string ADDED_SURE = "C'est noté : %s.";
    /** Le choix est probable ou incertain : Alexa dit ce qu'elle a mis et dans quel rayon, pour que l'erreur se voie. */
    public const string ADDED_GUESSED = "J'ai mis %s, %s.";
    public const string LIST_STARTED = "J'ai commencé une nouvelle liste avec %s.";
    public const string LIST_STARTED_GUESSED = "J'ai commencé une nouvelle liste avec %s, %s.";
    public const string PREVIOUS_LIST_SENT = 'Ta liste précédente est partie au drive.';

    /** Le produit dit est inconnu et des ingrédients de l'utilisateur lui ressemblent : « de quoi tu parles ? » (trois phrases). */
    public const string ASK_WHICH = 'Je ne connais pas %s. Tu veux dire %s ? Ou un nouveau produit ?';
    /** La même question, sans rappeler le produit (aide, silence). */
    public const string ASK_WHICH_SHORT = 'Tu veux dire %s ? Ou un nouveau produit ?';
    /** « Oui » alors qu'il y a plusieurs propositions. */
    public const string ASK_WHICH_AGAIN = 'Lequel ? %s. Ou un nouveau produit ?';
    /** Plusieurs ingrédients de l'utilisateur ont le même nom, dans des rayons différents : on demande le rayon (jamais « un nouveau produit »). */
    public const string ASK_TIE_SAME_NAME = '%s : %s ?';
    /** Plusieurs ingrédients se valent par des noms voisins : on cite le nom ET le rayon. */
    public const string ASK_TIE_NAMES = 'Tu veux dire %s ?';
    /** Le produit dit est inconnu et rien ne lui ressemble. */
    public const string ASK_CREATE = 'Je ne connais pas %s. Je le crée ?';
    /** Les mêmes questions quand les recettes n'ont pas encore été cherchées : l'issue « ou une recette ? » aiguille l'utilisateur (décision 13). */
    public const string ASK_WHICH_OR_MEAL = 'Je ne connais pas %s. Tu veux dire %s ? Ou un nouveau produit, ou une recette ?';
    public const string ASK_WHICH_SHORT_OR_MEAL = 'Tu veux dire %s ? Ou un nouveau produit, ou une recette ?';
    public const string ASK_WHICH_AGAIN_OR_MEAL = 'Lequel ? %s. Ou un nouveau produit, ou une recette ?';
    public const string ASK_CREATE_OR_MEAL = "Je ne connais pas %s. Je le crée ? Ou c'est une recette ?";
    public const string ASK_DEPARTMENT = 'Dans quel rayon ? Dis lesquels pour les entendre.';
    public const string ASK_UNIT = 'Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.';
    /** « Lesquels ? » au rayon : tous les rayons du catalogue (`%s`), puis la question. */
    public const string LIST_DEPARTMENTS = 'Les rayons : %s. Lequel ?';
    /** « Lesquelles ? » à l'unité : toutes les unités du catalogue (`%s`), puis la question. */
    public const string LIST_UNITS = 'Les unités : %s. Laquelle ?';
    public const string ASK_CONFIRM = "Je crée %s, %s, %s. C'est bon ?";
    public const string NOT_UNDERSTOOD = "Je n'ai pas compris.";
    /** Deux réponses incomprises de suite dans la même étape. */
    public const string GIVE_UP = "Je n'ai pas compris. On laisse tomber.";
    /** L'utilisateur refuse de créer le produit. */
    public const string DECLINED = "D'accord, je ne crée rien.";
    /** Il refuse de départager des ingrédients qui se valent : rien n'est ajouté. */
    public const string DECLINED_TIE = "D'accord, je n'ajoute rien.";
    /** Il a choisi une proposition : l'article est ajouté et la phrase retenue. */
    public const string REMEMBERED = "C'est noté : %s. Je m'en souviendrai.";
    public const string LIST_STARTED_REMEMBERED = "J'ai commencé une nouvelle liste avec %s. Je m'en souviendrai.";
    /** Il a départagé des ingrédients de même nom par leur rayon : on répète le rayon, et la phrase est retenue. */
    public const string REMEMBERED_IN = "C'est noté : %s, %s. Je m'en souviendrai.";
    public const string LIST_STARTED_REMEMBERED_IN = "J'ai commencé une nouvelle liste avec %s, %s. Je m'en souviendrai.";
    /** Le produit est créé et ajouté. */
    public const string CREATED = "C'est créé et noté : %s, rayon %s.";
    public const string CREATED_LIST_STARTED = "J'ai créé %s, rayon %s, et commencé une nouvelle liste.";

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Planifier un repas (ADR 0029 décision 13, VOX-305)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /** « Planifie » dit sans recette : on ne pose pas de question ouverte (la réponse brève serait comprise comme un produit), on donne l'exemple. */
    public const string MEAL_EXAMPLE = 'Dis par exemple : planifie le repas hachis parmentier.';
    /** Le repas est planifié (X : « hachis parmentier » ; Y : « 4 personnes »). */
    public const string MEAL_PLANNED = "C'est noté : %s, pour %s.";
    public const string MEAL_LIST_STARTED = "J'ai commencé une nouvelle liste avec %s, pour %s.";
    /** Il a choisi une proposition : le repas est planifié et la phrase retenue. */
    public const string MEAL_PLANNED_REMEMBERED = "C'est noté : %s, pour %s. Je m'en souviendrai.";
    public const string MEAL_LIST_STARTED_REMEMBERED = "J'ai commencé une nouvelle liste avec %s, pour %s. Je m'en souviendrai.";
    /** La recette est déjà dans la liste : elle n'y sera pas deux fois ; on demande le nouveau total, ou de ne rien changer (X : le repas, Y : « 4 personnes »). */
    public const string MEAL_ALREADY_PLANNED = 'Le repas %s est déjà prévu pour %s. Tu le veux pour combien, ou on ne change rien ?';
    /** Le nouveau total de personnes est posé. */
    public const string MEAL_SERVINGS_CHANGED = "C'est changé : %s, pour %s.";
    /** Il ne veut rien changer, ou dit le même nombre : rien n'est écrit. */
    public const string MEAL_UNCHANGED = "D'accord, je ne change rien.";
    /** Le repas a disparu de la liste entre la question et la réponse. */
    public const string MEAL_NO_LONGER_PLANNED = "Ce repas n'est plus dans ta liste.";
    /** Il refuse de choisir une recette : rien n'est planifié. */
    public const string DECLINED_MEAL = "D'accord, je ne planifie rien.";
    /** La recette dite est inconnue : jamais de création de recette à la voix. Les propositions, puis l'issue « ou un produit ? » tant que les produits n'ont pas été cherchés. */
    public const string ASK_MEAL_WHICH = 'Je ne trouve pas la recette %s. Tu veux dire %s ?';
    public const string ASK_MEAL_WHICH_OR_ITEM = 'Je ne trouve pas la recette %s. Tu veux dire %s ? Ou un produit ?';
    public const string ASK_MEAL_WHICH_SHORT = 'Tu veux dire %s ?';
    public const string ASK_MEAL_WHICH_SHORT_OR_ITEM = 'Tu veux dire %s ? Ou un produit ?';
    public const string ASK_MEAL_WHICH_AGAIN = 'Laquelle ? %s.';
    public const string ASK_MEAL_WHICH_AGAIN_OR_ITEM = 'Laquelle ? %s. Ou un produit ?';
    /** Aucune recette ne ressemble à la phrase : on demande si c'est un produit. */
    public const string ASK_MEAL_ITEM = "Je ne trouve pas la recette %s. C'est un produit ?";
    /** L'utilisateur n'a AUCUNE recette : on le lui dit, au lieu d'un « je ne trouve pas » qui ne l'aide pas (2026-10-26). */
    public const string ASK_MEAL_NO_RECIPES_ITEM = "Tu n'as encore aucune recette dans Repas. C'est un produit ?";
    /** Un ingrédient de la recette n'a pas de conversion vers son unité d'achat : les quantités de la liste ne se calculent pas (X : le nom de la recette). */
    public const string MEAL_MISSING_CONVERSION = 'Je ne peux pas calculer les quantités de %s. Ouvre la recette dans Repas.';
    /** Un ingrédient précis de la recette n'a pas de conversion vers son unité d'achat (comme l'application, qui le montre) : on le nomme. */
    public const string MEAL_MISSING_CONVERSION_OF = "Il manque une conversion pour %s. Ouvre la recette dans Repas.";
    /** La recette n'existe plus dans Repas, ou n'est pas à l'utilisateur. */
    public const string MEAL_GONE = 'Je ne retrouve plus cette recette dans Repas.';
    /** Ni produit ni recette ne portent ce nom : plus d'issue. */
    public const string NOTHING_FOUND = "Je ne trouve rien qui s'appelle %s.";

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer un repas, et proposer ce que la liste contient quand on ne comprend pas (ADR 0029 décision 12 amendée, VOX-306)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /** « Enlève » dit avec « le repas » mais sans recette : un exemple, jamais une question ouverte. */
    public const string REMOVE_MEAL_EXAMPLE = 'Dis par exemple : enlève le repas hachis parmentier.';
    /** Le repas quitte la liste (X : « hachis parmentier »). Si seul un nombre de personnes baisse : `MEAL_SERVINGS_CHANGED`. */
    public const string MEAL_REMOVED = "C'est retiré : le repas %s.";
    /** Le repas à retirer n'est pas dans la liste : les repas planifiés qui lui ressemblent, puis l'issue « ou un produit ? » tant que les produits n'ont pas été cherchés. */
    public const string ASK_REMOVE_MEAL_WHICH = 'Je ne trouve pas le repas %s dans ta liste. Tu veux dire %s ?';
    public const string ASK_REMOVE_MEAL_WHICH_OR_ITEM = 'Je ne trouve pas le repas %s dans ta liste. Tu veux dire %s ? Ou un produit ?';
    /** Aucun repas de la liste ne ressemble à la phrase : on demande si c'est un produit. */
    public const string ASK_REMOVE_MEAL_ITEM = "Je ne trouve pas le repas %s dans ta liste. C'est un produit ?";
    /** Le produit à retirer n'est pas dans la liste et la phrase ne désigne aucun de tes produits : les lignes qui lui ressemblent, puis l'issue « ou un repas ? ». */
    public const string ASK_REMOVE_ITEM_WHICH = "Il n'y a pas %s dans ta liste. Tu veux dire %s ?";
    public const string ASK_REMOVE_ITEM_WHICH_OR_MEAL = "Il n'y a pas %s dans ta liste. Tu veux dire %s ? Ou un repas ?";
    public const string ASK_REMOVE_ITEM_WHICH_SHORT = 'Tu veux dire %s ?';
    public const string ASK_REMOVE_ITEM_WHICH_SHORT_OR_MEAL = 'Tu veux dire %s ? Ou un repas ?';
    public const string ASK_REMOVE_ITEM_WHICH_AGAIN = 'Lequel ? %s.';
    public const string ASK_REMOVE_ITEM_WHICH_AGAIN_OR_MEAL = 'Lequel ? %s. Ou un repas ?';
    /** Aucune ligne de la liste ne ressemble à la phrase : on demande si c'est un repas. */
    public const string ASK_REMOVE_ITEM_MEAL = "Il n'y a pas %s dans ta liste. C'est un repas ?";
    /** Ni produit ni repas de la liste ne portent ce nom : plus d'issue. */
    public const string NOTHING_FOUND_IN_LIST = "Je ne trouve rien qui s'appelle %s dans ta liste.";

    /** Au-delà de ce nombre de mots, la réponse « C'est retiré : X. Il n'y en a plus dans ta liste. » perd sa seconde phrase. */
    private const int MAX_REMOVED_WORDS = 15;

    /** Longueur au-delà de laquelle un nom cité est coupé : une réponse parlée reste courte. */
    private const int MAX_SPOKEN_NAME_LENGTH = 60;

    private function __construct()
    {
    }

    /**
     * @param list<string> $names
     *
     * @return string « a », « a ou b », « a, b ou c » (trois au plus : le dialogue n'en propose pas davantage)
     */
    private static function joinNames(array $names): string
    {
        $spoken = array_map(self::speakable(...), $names);
        $last = array_pop($spoken);

        return $spoken === [] ? (string) $last : implode(', ', $spoken) . ' ou ' . $last;
    }

    /**
     * « a », « a ou b », « a, b ou c » pour des morceaux de phrase déjà prêts à être dits.
     *
     * @param list<string> $parts
     */
    private static function joinSpoken(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts) . ' ou ' . $last;
    }

    /**
     * @param list<DialogueOption> $options
     *
     * @return list<string>
     */
    private static function namesOf(array $options): array
    {
        return array_map(static fn(DialogueOption $option): string => $option->name, $options);
    }

    /**
     * La réponse à un article ajouté. `$isSure` : le nom entendu est exactement celui de l'ingrédient ; sinon Alexa cite aussi le
     * rayon. `$previousListSent` ne compte que si une liste a été créée.
     */
    public static function added(string $ingredientName, string $departmentName, bool $isSure, bool $listCreated, bool $previousListSent, ?AddedLine $line = null): string
    {
        $line ??= AddedLine::single();
        $ingredient = self::item($ingredientName, $line);
        $department = self::speakable($departmentName);

        if (!$listCreated) {
            // Un choix sûr sur une ligne déjà dans la liste : on dit le total ; un choix deviné dit d'abord ce qui a été mis et où
            if ($line->existed && $isSure) {
                return self::alreadyInList($line);
            }

            return $isSure
                ? sprintf(self::ADDED_SURE, $ingredient)
                : sprintf(self::ADDED_GUESSED, $ingredient, $department);
        }

        $started = $isSure
            ? sprintf(self::LIST_STARTED, $ingredient)
            : sprintf(self::LIST_STARTED_GUESSED, $ingredient, $department);

        return $previousListSent ? $started . ' ' . self::PREVIOUS_LIST_SENT : $started;
    }

    /**
     * « De quoi tu parles ? » : le produit `$name` est inconnu ; `$optionNames` sont les ingrédients de l'utilisateur qui lui ressemblent
     * (aucun : on propose de le créer). `$offerMeal` : les recettes n'ont pas encore été cherchées, l'issue « ou une recette ? » est proposée.
     *
     * @param list<string> $optionNames
     */
    public static function unknown(string $name, array $optionNames, bool $offerMeal = false): string
    {
        if ($optionNames === []) {
            return sprintf($offerMeal ? self::ASK_CREATE_OR_MEAL : self::ASK_CREATE, self::speakable($name));
        }

        return sprintf($offerMeal ? self::ASK_WHICH_OR_MEAL : self::ASK_WHICH, self::speakable($name), self::joinNames($optionNames));
    }

    /**
     * La question de l'étape où en est le dialogue (réponse à « aide », texte à répéter après un silence).
     */
    public static function question(SpokenDialogueState $state): string
    {
        return match ($state->step) {
            DialogueStep::CHOOSE => match (true) {
                $state->isRemovalProposal() => $state->isMeal() ? self::removeMealQuestion($state) : self::removeItemQuestion($state),
                $state->isMeal() => self::mealQuestion($state),
                $state->isTieBreak() => self::tieQuestion($state->options),
                $state->options === [] => sprintf($state->otherSideTried ? self::ASK_CREATE : self::ASK_CREATE_OR_MEAL, self::speakable($state->name)),
                default => sprintf($state->otherSideTried ? self::ASK_WHICH_SHORT : self::ASK_WHICH_SHORT_OR_MEAL, self::joinNames(self::namesOf($state->options))),
            },
            DialogueStep::SERVINGS => self::mealAlreadyPlanned($state->name, $state->plannedServings ?? 1),
            DialogueStep::DEPARTMENT => self::ASK_DEPARTMENT,
            DialogueStep::UNIT => self::ASK_UNIT,
            DialogueStep::CONFIRM => sprintf(
                self::ASK_CONFIRM,
                self::speakable($state->name),
                self::speakable($state->department->name ?? ''),
                self::speakable($state->unit->name ?? ''),
            ),
        };
    }

    /**
     * « Lesquels ? » : tous les choix de l'étape (les rayons ou les unités du catalogue), séparés par des virgules, puis la question « Lequel ? » /
     * « Laquelle ? ». Deux phrases ; la première est une énumération, la seule qui échappe à « 15 mots au plus » (elle ne s'écrit pas en
     * phrase : l'utilisateur écoute la liste pour choisir). `null` aux autres étapes ou quand le catalogue est vide (rien à énumérer).
     *
     * @param list<string> $names
     */
    public static function choices(SpokenDialogueState $state, array $names): ?string
    {
        $template = match ($state->step) {
            DialogueStep::DEPARTMENT => self::LIST_DEPARTMENTS,
            DialogueStep::UNIT => self::LIST_UNITS,
            default => null,
        };
        $spoken = array_values(array_filter(array_map(self::speakable(...), $names), static fn(string $name): bool => $name !== ''));
        if ($template === null || $spoken === []) {
            return null;
        }

        return sprintf($template, implode(', ', $spoken));
    }

    /** Une réponse qui n'a pas été comprise : on le dit, puis on repose la question de l'étape. */
    public static function notUnderstood(SpokenDialogueState $state): string
    {
        return self::NOT_UNDERSTOOD . ' ' . self::question($state);
    }

    /** « Oui », mais plusieurs propositions : laquelle ? */
    public static function whichAgain(SpokenDialogueState $state): string
    {
        if ($state->isRemovalProposal()) {
            $names = self::joinNames(self::namesOf($state->options));

            return $state->isMeal()
                ? sprintf($state->otherSideTried ? self::ASK_MEAL_WHICH_AGAIN : self::ASK_MEAL_WHICH_AGAIN_OR_ITEM, $names)
                : sprintf($state->otherSideTried ? self::ASK_REMOVE_ITEM_WHICH_AGAIN : self::ASK_REMOVE_ITEM_WHICH_AGAIN_OR_MEAL, $names);
        }
        if ($state->isMeal()) {
            return sprintf($state->otherSideTried ? self::ASK_MEAL_WHICH_AGAIN : self::ASK_MEAL_WHICH_AGAIN_OR_ITEM, self::joinNames(self::namesOf($state->options)));
        }
        if ($state->isTieBreak()) {
            return self::tieQuestion($state->options);
        }

        return sprintf($state->otherSideTried ? self::ASK_WHICH_AGAIN : self::ASK_WHICH_AGAIN_OR_MEAL, self::joinNames(self::namesOf($state->options)));
    }

    /**
     * « Tomate : en fruit et légume ou en surgelé ? » quand les noms sont les mêmes ; « Tu veux dire tomate en surgelé ou tomates pelées en
     * conserve ? » quand ils diffèrent. Les noms de rayons sont dits par `speakable()`.
     *
     * @param list<DialogueOption> $options
     */
    public static function tieQuestion(array $options): string
    {
        $names = array_unique(array_map(static fn(DialogueOption $option): string => self::speakable($option->name), $options));

        if (count($names) === 1) {
            $departments = array_map(static fn(DialogueOption $option): string => 'en ' . self::speakable($option->departmentName ?? ''), $options);
            $name = (string) reset($names);

            return sprintf(self::ASK_TIE_SAME_NAME, mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1), self::joinSpoken($departments));
        }

        $labels = array_map(static fn(DialogueOption $option): string => self::speakable($option->name) . ' en ' . self::speakable($option->departmentName ?? ''), $options);

        return sprintf(self::ASK_TIE_NAMES, self::joinSpoken($labels));
    }

    /**
     * Il a départagé par le rayon : l'article est ajouté, la phrase retenue, et Alexa répète le rayon qu'il a choisi. Une liste envoyée au drive
     * remplacée se dit à la place de la promesse, comme pour `remembered()`.
     */
    public static function rememberedIn(string $ingredientName, string $departmentName, bool $listCreated, bool $previousListSent, ?AddedLine $line = null): string
    {
        $line ??= AddedLine::single();
        $ingredient = self::item($ingredientName, $line);
        $department = self::speakable($departmentName);

        if (!$listCreated) {
            return $line->existed
                ? self::alreadyInList($line) . ' ' . self::REMEMBER_PROMISE
                : sprintf(self::REMEMBERED_IN, $ingredient, $department);
        }

        return $previousListSent
            ? sprintf(self::LIST_STARTED_GUESSED, $ingredient, $department) . ' ' . self::PREVIOUS_LIST_SENT
            : sprintf(self::LIST_STARTED_REMEMBERED_IN, $ingredient, $department);
    }

    /**
     * La proposition choisie est ajoutée, et Alexa promet de s'en souvenir. Quand une liste envoyée au drive est remplacée, on le dit à la
     * place de la promesse : la phrase est retenue quand même.
     */
    public static function remembered(string $ingredientName, bool $listCreated, bool $previousListSent, ?AddedLine $line = null): string
    {
        $line ??= AddedLine::single();
        $ingredient = self::item($ingredientName, $line);

        if (!$listCreated) {
            return $line->existed
                ? self::alreadyInList($line) . ' ' . self::REMEMBER_PROMISE
                : sprintf(self::REMEMBERED, $ingredient);
        }

        return $previousListSent
            ? sprintf(self::LIST_STARTED, $ingredient) . ' ' . self::PREVIOUS_LIST_SENT
            : sprintf(self::LIST_STARTED_REMEMBERED, $ingredient);
    }

    /**
     * Le produit est créé puis ajouté à la liste. Alexa répète le rayon où il vient d'être rangé (l'utilisateur l'a choisi à voix haute : il
     * entend ce qu'il a produit).
     */
    public static function created(string $ingredientName, string $departmentName, bool $listCreated, bool $previousListSent, ?AddedLine $line = null): string
    {
        $ingredient = self::item($ingredientName, $line ?? AddedLine::single());
        $department = self::speakable($departmentName);

        if (!$listCreated) {
            return sprintf(self::CREATED, $ingredient, $department);
        }

        $started = sprintf(self::CREATED_LIST_STARTED, $ingredient, $department);

        return $previousListSent ? $started . ' ' . self::PREVIOUS_LIST_SENT : $started;
    }

    /**
     * Le repas est planifié : « C'est noté : hachis parmentier, pour 4 personnes. » ; sans liste active, « J'ai commencé une nouvelle liste avec … » (et la
     * liste précédente envoyée au drive, dite à la place de la promesse de s'en souvenir, comme `remembered()`).
     */
    public static function mealPlanned(string $recipeName, int $servings, bool $listCreated, bool $previousListSent, bool $remembered = false): string
    {
        $name = self::speakable($recipeName);
        $people = self::people($servings);

        if (!$listCreated) {
            return sprintf($remembered ? self::MEAL_PLANNED_REMEMBERED : self::MEAL_PLANNED, $name, $people);
        }
        if ($previousListSent) {
            return sprintf(self::MEAL_LIST_STARTED, $name, $people) . ' ' . self::PREVIOUS_LIST_SENT;
        }

        return sprintf($remembered ? self::MEAL_LIST_STARTED_REMEMBERED : self::MEAL_LIST_STARTED, $name, $people);
    }

    /** La recette est déjà dans la liste : le repas, pour combien, et la question du nouveau total. */
    public static function mealAlreadyPlanned(string $recipeName, int $servings): string
    {
        return sprintf(self::MEAL_ALREADY_PLANNED, self::speakable($recipeName), self::people($servings));
    }

    /** Le nouveau total de personnes est posé. */
    public static function mealServingsChanged(string $recipeName, int $servings): string
    {
        return sprintf(self::MEAL_SERVINGS_CHANGED, self::speakable($recipeName), self::people($servings));
    }

    /**
     * « Je ne trouve pas la recette X. Tu veux dire A, B ou C ? Ou un produit ? » : la recette est inconnue ; `$optionNames` sont les recettes de
     * l'utilisateur qui lui ressemblent (aucune : on demande si c'est un produit). `$offerItem` : les produits n'ont pas encore été cherchés, l'issue
     * « ou un produit ? » est proposée. Sans proposition ni issue, il n'y a plus rien à dire que « Je ne trouve rien qui s'appelle X. ».
     *
     * @param list<string> $optionNames
     * @param bool         $hasRecipes   l'utilisateur a au moins une recette (sinon il n'a aucune proposition à entendre : Alexa le lui dit)
     */
    public static function unknownMeal(string $name, array $optionNames, bool $offerItem, bool $hasRecipes = true): string
    {
        if (!$hasRecipes) {
            return $offerItem ? self::ASK_MEAL_NO_RECIPES_ITEM : sprintf(self::NOTHING_FOUND, self::speakable($name));
        }
        if ($optionNames === []) {
            return sprintf($offerItem ? self::ASK_MEAL_ITEM : self::NOTHING_FOUND, self::speakable($name));
        }

        return sprintf($offerItem ? self::ASK_MEAL_WHICH_OR_ITEM : self::ASK_MEAL_WHICH, self::speakable($name), self::joinNames($optionNames));
    }

    /**
     * La phrase claire d'un échec CONNU de la commande d'un repas (`SpokenMealFailure`, une seule table) ; `null` pour toute autre panne, qui garde
     * `FAILURE`.
     */
    public static function mealFailure(string $reason, ?string $recipeName, ?string $ingredientName = null): ?string
    {
        $ingredient = self::speakable($ingredientName ?? '');

        return match ($reason) {
            SpokenMealFailure::MISSING_CONVERSION => $ingredient !== ''
                ? sprintf(self::MEAL_MISSING_CONVERSION_OF, $ingredient)
                : sprintf(self::MEAL_MISSING_CONVERSION, self::speakable($recipeName ?? '')),
            SpokenMealFailure::RECIPE_GONE => self::MEAL_GONE,
            default => null,
        };
    }

    /** Ni produit ni recette ne portent ce nom. */
    public static function nothingFound(string $name): string
    {
        return sprintf(self::NOTHING_FOUND, self::speakable($name));
    }

    /** La question de l'étape « choisir une recette » (à répéter après un silence ou une réponse incomprise). */
    private static function mealQuestion(SpokenDialogueState $state): string
    {
        if ($state->options === []) {
            return sprintf($state->otherSideTried ? self::NOTHING_FOUND : self::ASK_MEAL_ITEM, self::speakable($state->name));
        }

        return sprintf($state->otherSideTried ? self::ASK_MEAL_WHICH_SHORT : self::ASK_MEAL_WHICH_SHORT_OR_ITEM, self::joinNames(self::namesOf($state->options)));
    }

    /** Le repas quitte la liste. */
    public static function mealRemoved(string $recipeName): string
    {
        return sprintf(self::MEAL_REMOVED, self::speakable($recipeName));
    }

    /**
     * « Je ne trouve pas le repas X dans ta liste. Tu veux dire A, B ou C ? Ou un produit ? » : aucun repas planifié ne se laisse choisir ; `$optionNames` sont
     * les repas de la liste qui lui ressemblent (aucun : on demande si c'est un produit). `$offerItem` : les produits n'ont pas encore été cherchés. Sans
     * proposition ni issue : « Je ne trouve rien qui s'appelle X dans ta liste. ».
     *
     * @param list<string> $optionNames
     */
    public static function unknownMealToRemove(string $name, array $optionNames, bool $offerItem): string
    {
        if ($optionNames === []) {
            return sprintf($offerItem ? self::ASK_REMOVE_MEAL_ITEM : self::NOTHING_FOUND_IN_LIST, self::speakable($name));
        }

        return sprintf($offerItem ? self::ASK_REMOVE_MEAL_WHICH_OR_ITEM : self::ASK_REMOVE_MEAL_WHICH, self::speakable($name), self::joinNames($optionNames));
    }

    /**
     * « Il n'y a pas de X dans ta liste. Tu veux dire A, B ou C ? Ou un repas ? » : la phrase ne désigne aucun de tes produits et aucune ligne de la liste ne
     * la porte ; `$optionNames` sont les lignes qui lui ressemblent (aucune : on demande si c'est un repas). `$offerMeal` : les repas n'ont pas encore été
     * cherchés. Sans proposition ni issue : « Je ne trouve rien qui s'appelle X dans ta liste. ».
     *
     * @param list<string> $optionNames
     */
    public static function unknownItemToRemove(string $name, array $optionNames, bool $offerMeal): string
    {
        if ($optionNames === []) {
            return $offerMeal ? sprintf(self::ASK_REMOVE_ITEM_MEAL, self::of(self::speakable($name))) : self::nothingFoundInList($name);
        }

        return sprintf($offerMeal ? self::ASK_REMOVE_ITEM_WHICH_OR_MEAL : self::ASK_REMOVE_ITEM_WHICH, self::of(self::speakable($name)), self::joinNames($optionNames));
    }

    /** Ni produit ni repas de la liste ne portent ce nom. */
    public static function nothingFoundInList(string $name): string
    {
        return sprintf(self::NOTHING_FOUND_IN_LIST, self::speakable($name));
    }

    /** La question de l'étape « quel repas retirer ? » (à répéter après un silence ou une réponse incomprise). */
    private static function removeMealQuestion(SpokenDialogueState $state): string
    {
        if ($state->options === []) {
            return sprintf($state->otherSideTried ? self::NOTHING_FOUND_IN_LIST : self::ASK_REMOVE_MEAL_ITEM, self::speakable($state->name));
        }

        return sprintf($state->otherSideTried ? self::ASK_MEAL_WHICH_SHORT : self::ASK_MEAL_WHICH_SHORT_OR_ITEM, self::joinNames(self::namesOf($state->options)));
    }

    /** La question de l'étape « quel produit retirer ? » (à répéter après un silence ou une réponse incomprise). */
    private static function removeItemQuestion(SpokenDialogueState $state): string
    {
        if ($state->options === []) {
            return $state->otherSideTried ? self::nothingFoundInList($state->name) : sprintf(self::ASK_REMOVE_ITEM_MEAL, self::of(self::speakable($state->name)));
        }

        return sprintf($state->otherSideTried ? self::ASK_REMOVE_ITEM_WHICH_SHORT : self::ASK_REMOVE_ITEM_WHICH_SHORT_OR_MEAL, self::joinNames(self::namesOf($state->options)));
    }

    /** « 1 personne », « 4 personnes ». */
    public static function people(int $servings): string
    {
        return $servings . ($servings >= 2 ? ' personnes' : ' personne');
    }

    /**
     * La réponse à un article retiré (ADR 0029 décision 12) : « C'est retiré : croquette. Il n'y en a plus dans ta liste. » quand la ligne disparaît,
     * « C'est retiré, il t'en reste 2 paquets dans ta liste. » quand elle diminue, et, quand l'unité dite n'est pas celle de la ligne, « C'est retiré
     * en paquets, il t'en reste 2 dans ta liste. ».
     */
    public static function removed(string $ingredientName, RemovedLine $line): string
    {
        if ($line->isGone()) {
            $gone = new AddedLine($line->removed, 0.0, $line->unitSlug, $line->unitName, existed: true, unitCited: $line->unitCited, unitSubstituted: $line->unitSubstituted);
            $item = self::item($ingredientName, $gone);

            // Une réponse parlée reste courte : quand ce qui a été retiré est long à dire, la seconde phrase (« Il n'y en a plus… ») saute
            $full = sprintf(self::REMOVED_GONE, $item);

            return count(preg_split('/\s+/u', $full)) > self::MAX_REMOVED_WORDS ? sprintf(self::REMOVED_GONE_SHORT, $item) : $full;
        }

        $left = self::amount($line->remaining, $line->unitName, $line->isCount());

        return $line->unitSubstituted
            ? sprintf(self::REMOVED_LEFT_IN_UNIT, self::pluralize(self::speakable($line->unitName)), $left)
            : sprintf(self::REMOVED_LEFT, $left);
    }

    /** Sans liste active : celle qui est partie au drive se dit à part (« Ta liste est déjà partie au drive. »), sinon « Tu n'as pas de liste en cours. ». */
    public static function noActiveList(bool $listSent): string
    {
        return $listSent ? self::LIST_SENT : self::NO_LIST;
    }

    /** Retirer un produit qui n'est pas dans la liste (le nom de l'ingrédient reconnu, à défaut celui qui a été entendu). */
    public static function notInList(string $name): string
    {
        return sprintf(self::NOT_IN_LIST, self::of(self::speakable($name)));
    }

    /** Consulter un produit qui n'est pas dans la liste. */
    public static function notInListAnswer(string $name): string
    {
        return sprintf(self::NOT_IN_LIST_NO, self::of(self::speakable($name)));
    }

    /** Consulter un produit qui est dans la liste : « Oui, tu en as 2 paquets. », « Oui, tu en as 3. » (unité « unité »). */
    public static function inList(ListLine $line): string
    {
        return sprintf(self::IN_LIST, self::amount($line->quantity, $line->unitName, $line->isCount()));
    }

    /**
     * Consulter un produit porté par plusieurs lignes (même nom dans deux rayons) : une courte énumération, une quantité par rayon, « Oui : 2
     * tomates en fruit et légume, 1 en surgelé. ». La seule réponse de consultation qui énumère, comme `choices()` : deux ou trois lignes au plus.
     *
     * @param list<ListLine> $lines
     */
    public static function inListMany(array $lines): string
    {
        $labels = [];
        foreach (array_values($lines) as $index => $line) {
            $labels[] = self::lineLabel($line, $index === 0);
        }

        return sprintf(self::IN_LIST_MANY, implode(', ', $labels));
    }

    /**
     * Une ligne dans l'énumération : « 2 tomates en fruit et légume » pour la première (elle dit le produit), « 1 en surgelé » pour les suivantes ;
     * avec une unité, « 2 paquets de croquette en … » / « 1 paquet en … ».
     */
    private static function lineLabel(ListLine $line, bool $withName): string
    {
        $name = self::speakable($line->name);
        $count = self::number($line->quantity);
        $department = 'en ' . self::speakable($line->departmentName);

        if ($line->isCount()) {
            return $withName ? $count . ' ' . ($line->quantity >= 2 ? self::pluralize($name) : $name) . ' ' . $department : $count . ' ' . $department;
        }

        $quantity = $count . ' ' . self::unitWord($line->unitName, $line->quantity);

        return $withName ? $quantity . ' ' . self::of($name) . ' ' . $department : $quantity . ' ' . $department;
    }

    /** Une quantité dite : « 3 » (unité « unité »), « 2 paquets » (sinon, l'unité au pluriel dès deux). */
    private static function amount(float $quantity, string $unitName, bool $isCount): string
    {
        $count = self::number($quantity);

        return $isCount ? $count : $count . ' ' . self::unitWord($unitName, $quantity);
    }

    /**
     * Ce qui a été ajouté, dit en peu de mots : « pain » (une unité, rien de cité), « 3 croquettes » (une quantité), « 2 kilos de tomate »
     * (une unité citée, jamais l'unité « unité »). Quand l'unité dite n'a pas servi, c'est l'unité de la ligne qui se dit (« 2 paquets de
     * croquette ») ; si la ligne compte des unités, « 2 tomates » ne laisse pas croire à des kilos.
     */
    public static function item(string $ingredientName, AddedLine $line): string
    {
        $name = self::speakable($ingredientName);
        $count = self::number($line->added);

        if ($line->unitCited && !$line->isCount()) {
            return $count . ' ' . self::unitWord($line->unitName, $line->added) . ' ' . self::of($name);
        }
        if (abs($line->added - 1.0) < 0.0001) {
            return $name;
        }

        return $count . ' ' . ($line->added >= 2 ? self::pluralize($name) : $name);
    }

    /**
     * Le total de la ligne quand l'article y était déjà : « C'est ajouté, tu en as maintenant 3 paquets dans ta liste. », « … maintenant 5 dans
     * ta liste. » (unité « unité »), ou, quand l'unité dite n'est pas celle de la ligne, « C'est ajouté en paquets, tu en as maintenant 5 dans ta liste. ».
     */
    private static function alreadyInList(AddedLine $line): string
    {
        $total = self::number($line->total);
        if ($line->unitSubstituted) {
            return sprintf(self::TOTAL_ADDED_IN_UNIT, self::pluralize(self::speakable($line->unitName)), $total);
        }

        return sprintf(self::TOTAL_ADDED, $line->isCount() ? $total : $total . ' ' . self::unitWord($line->unitName, $line->total));
    }

    /** Le nom d'une unité, au pluriel dès deux : « 1,5 kilo », « 2 kilos », « 3 cuillères à soupe ». */
    private static function unitWord(string $unitName, float $quantity): string
    {
        $unit = self::speakable($unitName);

        return $quantity >= 2 ? self::pluralize($unit) : $unit;
    }

    /** Un entier se dit sans virgule (« 3 »), un décimal avec une virgule et deux chiffres au plus (« 1,5 »). */
    private static function number(float $quantity): string
    {
        $rounded = round($quantity, 2);
        if (abs($rounded - round($rounded)) < 0.0001) {
            return (string) (int) round($rounded);
        }

        return str_replace('.', ',', rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.'));
    }

    /** Le pluriel du premier mot : « paquet » donne « paquets », « cuillère à soupe » donne « cuillères à soupe ». Sans règle fine : c'est dit, pas écrit. */
    private static function pluralize(string $text): string
    {
        $words = explode(' ', $text, 2);
        if ($words[0] !== '' && preg_match('/[sxz]$/u', $words[0]) !== 1) {
            $words[0] .= 's';
        }

        return implode(' ', $words);
    }

    /** « de tomate », « d'ail » (voyelle ou h muet). */
    private static function of(string $name): string
    {
        $aspirated = preg_match('/^h(?:aricot|achis|amburger|omard|areng|oumous|arissa|alva)/u', $name) === 1;

        return preg_match('/^[aeiouyàâäéèêëîïôöùûüœæ]/u', $name) === 1 || (!$aspirated && str_starts_with($name, 'h')) ? "d'" . $name : 'de ' . $name;
    }

    /**
     * Un nom prêt à être dit : en minuscules (le texte se lit mieux), espaces réduits, caractères de contrôle retirés, coupé s'il est
     * trop long.
     */
    public static function speakable(string $text): string
    {
        // « fruit & légume » : Alexa lit mieux « et » que le signe
        $clean = preg_replace(['/\p{C}+/u', '/\s*&\s*/u', '/\s+/u'], [' ', ' et ', ' '], $text);
        $clean = mb_strtolower(trim($clean ?? ''));

        return mb_strlen($clean) > self::MAX_SPOKEN_NAME_LENGTH ? rtrim(mb_substr($clean, 0, self::MAX_SPOKEN_NAME_LENGTH)) : $clean;
    }
}
