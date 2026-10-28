<?php

namespace Repas\Tests\Helper;


/**
 * Le texte qu'un lecteur voit dans le HTML d'un e-mail : sans l'en-tête ni les styles, sans balises ni entités, un bloc par ligne.
 * Sert aux tests de voix (tutoiement, phrases courtes, pas de point d'exclamation) sur le texte visible, pas sur le code.
 */
final class VisibleEmailText
{
    /** L'accroche cachée en tête du corps, que les messageries montrent après l'objet. */
    public static function preheaderOf(string $html): string
    {
        return 1 === preg_match('#<body[^>]*>\s*<div style="display:none;[^"]*">(.*?)&\#847;#s', $html, $matches) ? html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    public static function of(string $html): string
    {
        $html = preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#</(p|h1|tr|div)>|<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Les caractères invisibles du texte d'aperçu (espaces insécables, joints de largeur nulle) ne sont pas du texte
        $text = preg_replace('/[\x{034F}\x{200B}\x{200C}\x{00A0}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace("/[ \t]*\n[ \t\n]*/", "\n", $text) ?? $text);
    }
}
