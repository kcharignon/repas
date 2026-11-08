<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\MarmitonImportException;

/**
 * L'adresse d'une recette Marmiton, telle que l'application accepte de la lire (ADR 0037 décision 12). Rien de ce que la personne colle
 * n'est jamais appelé : on n'en garde que le nom de la recette et son identifiant, et l'adresse appelée est RECONSTRUITE par
 * `canonical()` / `fetchUrl()` (toujours `https://www.marmiton.org/recettes/recette_{nom}_{id}.aspx`). C'est la défense contre l'appel
 * d'une adresse choisie par un tiers (SSRF) : aucun hôte, port, nom d'utilisateur, schéma, requête ni fragment reçu n'est conservé.
 *
 * Forme permise (le texte est d'abord débarrassé des espaces, tabulations et retours à la ligne qui l'entourent, puis passé en minuscules
 * ASCII) : `https` ou `http` (relevé en `https`), l'hôte `www.marmiton.org` ou `marmiton.org` (ni sous-domaine, ni port, ni identifiant
 * `user@`, ni IP, ni lettre hors ASCII), le chemin `/recettes/recette_{nom}_{id}.aspx` (nom de 1 à 150 caractères parmi `a-z`, `0-9` et
 * `-` ; identifiant de 1 à 12 chiffres, sans zéro en tête), puis, au plus, une requête ou un fragment, écartés. 300 caractères au plus.
 * Aucun analyseur d'adresse n'est utilisé : une seule expression, ancrée aux deux bouts, sur un texte fait de caractères ASCII visibles.
 */
final readonly class MarmitonUrl
{
    public const string HOST = 'www.marmiton.org';
    public const int MAX_LENGTH = 300;

    private const string PATTERN = '~^https?://(?:www\.)?marmiton\.org/recettes/recette_([a-z0-9-]{1,150})_([1-9][0-9]{0,11})\.aspx(?:[?#]\S*)?\z~D';

    private function __construct(
        /** Le nom de la recette dans l'adresse (`the-tarte-au-citron-meringuee`) ; il ne sert qu'à reconstruire l'adresse. */
        public string $slug,
        /** L'identifiant Marmiton (`22082`) : de 1 à 12 chiffres, c'est le tag de la recette neutre. */
        public string $id,
    ) {
    }

    /**
     * @throws MarmitonImportException `urlInvalid` pour tout ce qui n'est pas une adresse de recette Marmiton
     */
    public static function parse(string $raw): self
    {
        $value = trim($raw, " \t\n\r");
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw MarmitonImportException::urlInvalid();
        }

        // Rien que des caractères ASCII visibles : ni espace, ni retour à la ligne, ni octet nul, ni lettre hors ASCII (IDN, variantes Unicode).
        if (preg_match('/[^\x21-\x7e]/', $value) === 1) {
            throw MarmitonImportException::urlInvalid();
        }

        // `strtolower` ne touche que l'ASCII depuis PHP 8 : une lettre Unicode qui se « replierait » vers une lettre ASCII reste refusée.
        if (preg_match(self::PATTERN, strtolower($value), $matches) !== 1) {
            throw MarmitonImportException::urlInvalid();
        }

        return new self($matches[1], $matches[2]);
    }

    /** L'adresse de la page : toujours en `https`, sur `www.marmiton.org`, reconstruite à partir du nom et de l'identifiant. */
    public function canonical(): string
    {
        return sprintf('https://%s/recettes/recette_%s_%s.aspx', self::HOST, $this->slug, $this->id);
    }

    /** L'adresse appelée : la même que `canonical()`, la seule qui sort jamais de l'application. */
    public function fetchUrl(): string
    {
        return $this->canonical();
    }
}
