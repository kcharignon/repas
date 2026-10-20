<?php

namespace Repas\Drive\Domain\Model;


use Repas\Drive\Domain\Exception\DriveException;

/**
 * Le texte qui a fait trouver un produit sur le site d'une enseigne (« céréales coussins » pour l'ingrédient « Céréale coussin »),
 * mémorisé avec la préférence d'un ingrédient pour chercher un remplaçant avec ce texte quand le produit n'est plus disponible
 * (DRV-650). C'est un texte tapé par une personne : privé, lié à sa préférence, effacé avec son compte.
 *
 * Normalisé à la création : les espaces de bord sont retirées et chaque suite d'espaces (dont les insécables) devient une seule
 * espace ; la casse et les accents sont gardés. Refusé : un texte de moins de 2 ou de plus de 80 caractères (après normalisation),
 * ou qui contient un caractère de contrôle (saut de ligne, tabulation…), de format (sens de lecture, largeur nulle…) ou un séparateur
 * de ligne ou de paragraphe. Le client qui envoie un texte tapé le nettoie de la même façon avant : une recherche refusée fait
 * refuser tout le lot de choix.
 *
 * Rien ici ne dépend d'une enseigne : la valeur, elle, n'a de sens que pour l'enseigne de la préférence qui la porte.
 */
final readonly class SearchQuery
{
    public const int MIN_LENGTH = 2;
    public const int MAX_LENGTH = 80;

    /** Mêmes catégories que les caractères refusés d'un libellé de produit, sans la liste d'exceptions (invisibles d'autres catégories). */
    private const string FORBIDDEN_CHARACTERS_PATTERN = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * @throws DriveException `searchQueryInvalid()` (422) pour un texte hors des bornes ou avec un caractère refusé
     */
    public static function of(string $text): self
    {
        // `false` si le texte n'est pas de l'UTF-8 valide : refusé comme un caractère interdit
        if (preg_match(self::FORBIDDEN_CHARACTERS_PATTERN, $text) !== 0) {
            throw DriveException::searchQueryInvalid();
        }

        // Il ne reste que des espaces de catégorie `Zs` (espace, insécables) : une suite devient une seule espace
        $normalized = trim((string) preg_replace('/\p{Zs}+/u', ' ', $text), ' ');
        $length = mb_strlen($normalized);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw DriveException::searchQueryInvalid();
        }

        return new self($normalized);
    }

    /**
     * Reconstruction depuis la persistance : aucun contrôle (la valeur a été normalisée par `of()` à l'écriture).
     */
    public static function load(string $stored): self
    {
        return new self($stored);
    }
}
