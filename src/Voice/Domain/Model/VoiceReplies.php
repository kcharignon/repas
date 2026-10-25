<?php

namespace Repas\Voice\Domain\Model;


/**
 * Tout ce qu'Alexa dit de la part de Repas (ADR 0029 décision 2, skill `repas-po`) : le catalogue des textes, au même endroit pour
 * qu'on les relise ensemble. La voix est celle du produit : tutoiement, une à deux phrases, 15 mots au plus par phrase, aucun
 * avertissement, aucun mot technique (« ingrédient » n'apparaît que dans « tes ingrédients »). Un proche doit pouvoir dire chaque phrase à voix
 * haute ; `VoiceRepliesTest` impose la longueur et le vocabulaire.
 *
 * Une seule exception à « deux phrases » : la question « de quoi tu parles ? » d'un produit inconnu (`ASK_WHICH`, `ASK_WHICH_AGAIN`), trois
 * phrases, parce qu'elle dit ce qui n'est pas connu, les propositions et l'issue « un nouveau produit » (ADR 0029 décision 11).
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
    /** La séance s'ouvre (« Alexa, ouvre mes courses »), ou le nom à ajouter n'a pas été compris. */
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
    /** Le produit dit est inconnu et rien ne lui ressemble. */
    public const string ASK_CREATE = 'Je ne connais pas %s. Je le crée ?';
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
    /** Il a choisi une proposition : l'article est ajouté et la phrase retenue. */
    public const string REMEMBERED = "C'est noté : %s. Je m'en souviendrai.";
    public const string LIST_STARTED_REMEMBERED = "J'ai commencé une nouvelle liste avec %s. Je m'en souviendrai.";
    /** Le produit est créé et ajouté. */
    public const string CREATED = "C'est créé et noté : %s, rayon %s.";
    public const string CREATED_LIST_STARTED = "J'ai créé %s, rayon %s, et commencé une nouvelle liste.";
    /** Ajoutée à la réponse quand la séance reste ouverte. */
    public const string ANYTHING_ELSE = 'Autre chose ?';

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
    public static function added(string $ingredientName, string $departmentName, bool $isSure, bool $listCreated, bool $previousListSent): string
    {
        $ingredient = self::speakable($ingredientName);
        $department = self::speakable($departmentName);

        if (!$listCreated) {
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
     * (aucun : on propose de le créer).
     *
     * @param list<string> $optionNames
     */
    public static function unknown(string $name, array $optionNames): string
    {
        return $optionNames === []
            ? sprintf(self::ASK_CREATE, self::speakable($name))
            : sprintf(self::ASK_WHICH, self::speakable($name), self::joinNames($optionNames));
    }

    /**
     * La question de l'étape où en est le dialogue (réponse à « aide », texte à répéter après un silence).
     */
    public static function question(SpokenDialogueState $state): string
    {
        return match ($state->step) {
            DialogueStep::CHOOSE => $state->options === []
                ? sprintf(self::ASK_CREATE, self::speakable($state->name))
                : sprintf(self::ASK_WHICH_SHORT, self::joinNames(self::namesOf($state->options))),
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
        return sprintf(self::ASK_WHICH_AGAIN, self::joinNames(self::namesOf($state->options)));
    }

    /**
     * La proposition choisie est ajoutée, et Alexa promet de s'en souvenir. Quand une liste envoyée au drive est remplacée, on le dit à la
     * place de la promesse (trois phrases avec « Autre chose ? » au plus) : la phrase est retenue quand même.
     */
    public static function remembered(string $ingredientName, bool $listCreated, bool $previousListSent): string
    {
        $ingredient = self::speakable($ingredientName);

        if (!$listCreated) {
            return sprintf(self::REMEMBERED, $ingredient);
        }

        return $previousListSent
            ? sprintf(self::LIST_STARTED, $ingredient) . ' ' . self::PREVIOUS_LIST_SENT
            : sprintf(self::LIST_STARTED_REMEMBERED, $ingredient);
    }

    /**
     * Le produit est créé puis ajouté à la liste. Alexa répète le rayon où il vient d'être rangé (l'utilisateur l'a choisi à voix haute : il
     * entend ce qu'il a produit).
     */
    public static function created(string $ingredientName, string $departmentName, bool $listCreated, bool $previousListSent): string
    {
        $ingredient = self::speakable($ingredientName);
        $department = self::speakable($departmentName);

        if (!$listCreated) {
            return sprintf(self::CREATED, $ingredient, $department);
        }

        $started = sprintf(self::CREATED_LIST_STARTED, $ingredient, $department);

        return $previousListSent ? $started . ' ' . self::PREVIOUS_LIST_SENT : $started;
    }

    /**
     * La même réponse, suivie de l'invitation à continuer (séance ouverte).
     */
    public static function withInvitation(string $reply): string
    {
        return $reply . ' ' . self::ANYTHING_ELSE;
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
