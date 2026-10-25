<?php

namespace Repas\Voice\Domain\Model;


/**
 * Tout ce qu'Alexa dit de la part de Repas (ADR 0029 décision 2, skill `repas-po`) : le catalogue des textes, au même endroit pour
 * qu'on les relise ensemble. La voix est celle du produit : tutoiement, une à deux phrases, 15 mots au plus par phrase, aucun
 * avertissement, aucun mot technique (« ingrédient » n'apparaît que dans « tes ingrédients »). Un proche doit pouvoir dire chaque phrase à voix
 * haute ; `VoiceRepliesTest` impose la longueur et le vocabulaire.
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
    public const string NOT_FOUND = 'Je ne trouve pas %s dans tes ingrédients.';
    /** Ajoutée à la réponse quand la séance reste ouverte. */
    public const string ANYTHING_ELSE = 'Autre chose ?';

    /** Longueur au-delà de laquelle un nom cité est coupé : une réponse parlée reste courte. */
    private const int MAX_SPOKEN_NAME_LENGTH = 60;

    private function __construct()
    {
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

    public static function notFound(string $phrase): string
    {
        return sprintf(self::NOT_FOUND, self::speakable($phrase));
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
        $clean = preg_replace(['/\p{C}+/u', '/\s+/u'], ' ', $text);
        $clean = mb_strtolower(trim($clean ?? ''));

        return mb_strlen($clean) > self::MAX_SPOKEN_NAME_LENGTH ? rtrim(mb_substr($clean, 0, self::MAX_SPOKEN_NAME_LENGTH)) : $clean;
    }
}
