<?php

namespace Repas\Repas\Domain\Service\Marmiton;


use Repas\Shared\Domain\Tool\StringTool;

/**
 * Le nom d'une recette Marmiton, sans le suffixe que la page ajoute à son titre (ADR 0037 décision 2) : « Tarte au citron meringuée : la
 * meilleure recette » devient « Tarte au citron meringuée ». Pur et sans exception.
 *
 * Un seul suffixe est retiré, en fin de titre, sans tenir compte de la casse : « : la meilleure recette », « : recette facile » (et
 * ses variantes : simple, rapide, de grand-mère, du chef…), « - Marmiton », « | Marmiton.org », « : recette de cuisine Marmiton ». Les
 * espaces sont normalisées, la première lettre passe en majuscule (`StringTool::capitalizeFirst`, ADR 0030) et le nom est borné à 255
 * caractères. Un titre vide après nettoyage est rendu tel quel : c'est MAR-003 qui refuse la recette sans nom.
 */
final readonly class MarmitonRecipeTitle
{
    public const int MAX_LENGTH = 255;

    /** Tout ce qui dépasse est ignoré avant de toucher à l'expression régulière. */
    private const int MAX_INPUT_LENGTH = 1000;

    private const string SUFFIX = '/(?:\s*:\s*|\s*[|–—]\s*|\s+-\s*)'
    . '(?:(?:la\s+)?meilleure\s+recette'
    . '|recette(?:\s+(?:facile|simple|rapide|express|traditionnelle|originale|maison|inratable|économique|familiale|de\s+cuisine'
    . '|de\s+(?:ma\s+)?grand[\s-]?mère|du\s+chef))*(?:\s+marmiton(?:\.org)?)?'
    . '|marmiton(?:\.org)?)\s*$/iu';

    private function __construct()
    {
    }

    public static function clean(string $raw): string
    {
        $title = mb_substr(mb_scrub($raw), 0, self::MAX_INPUT_LENGTH);
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim((string) preg_replace('/[\p{Z}\s\x{200B}\x{FEFF}]+/u', ' ', $title));
        $title = trim((string) preg_replace(self::SUFFIX, '', $title));

        return mb_substr(StringTool::capitalizeFirst($title), 0, self::MAX_LENGTH);
    }
}
