<?php

namespace Repas\User\Domain\Model;


use Repas\User\Domain\Exception\IdentityException;

/**
 * Ce qu'un jeton d'identité dit de la personne, une fois sa signature et ses revendications vérifiées
 * (`IdentityTokenVerifier`, ADR 0024 décision 6). Valeur immuable, jamais enregistrée ni journalisée telle quelle.
 *
 * - `subject` est LA clé de l'identité (le `sub` de Google, jamais réutilisé) ; l'adresse ne sert qu'à retrouver un compte
 *   existant à la première connexion, et seulement si elle fait foi (`emailIsAuthoritative()`).
 * - `email` est normalisé comme l'identifiant de connexion (`User::normalizeEmail`).
 * - `nonce` est recopié du jeton, `null` s'il n'en porte pas : c'est au cas d'usage de le consommer (AUTH-006) et de refuser
 *   un jeton sans nonce.
 * - `hostedDomain` (`hd`, domaine Google Workspace) est transporté mais aucune règle ne le lit (décision D2 : Gmail seulement).
 */
final readonly class VerifiedIdentity
{
    /** Domaines pour lesquels Google fait foi de l'adresse (page « Vérifier le jeton d'identité », ADR 0024). */
    private const array AUTHORITATIVE_DOMAINS = ['gmail.com', 'googlemail.com'];

    public string $email;

    /**
     * @throws IdentityException un sujet ou une adresse vides ne désignent personne
     */
    public function __construct(
        public IdentityProvider $provider,
        public string $subject,
        string $email,
        public bool $emailVerified,
        public ?string $nonce,
        public ?string $hostedDomain,
    ) {
        $this->email = User::normalizeEmail($email);

        if (trim($subject) === '' || $this->email === '') {
            throw IdentityException::invalidCredential();
        }
    }

    /**
     * L'adresse fait foi quand le fournisseur l'a vérifiée ET qu'il en est le propriétaire : `@gmail.com` ou `@googlemail.com`
     * (domaine lu après la DERNIÈRE `@`, comparé exactement). Une adresse d'un autre domaine, même « vérifiée », a pu changer
     * de main (domaine recyclé) : elle ne suffit pas à lier un compte existant (ADR 0024 décision 9).
     */
    public function emailIsAuthoritative(): bool
    {
        if (!$this->emailVerified) {
            return false;
        }

        $at = strrpos($this->email, '@');
        if ($at === false || $at === 0) {
            return false;
        }

        return in_array(substr($this->email, $at + 1), self::AUTHORITATIVE_DOMAINS, true);
    }
}
