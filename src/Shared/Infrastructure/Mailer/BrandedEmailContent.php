<?php

namespace Repas\Shared\Infrastructure\Mailer;


/**
 * Ce qu'un e-mail de Repas dit, sans aucune mise en page (ADR 0031) : la mise en page est celle de `BrandedEmailLayout`, commune à tous les e-mails.
 * Texte libre, jamais du HTML : la mise en page échappe tout.
 */
final readonly class BrandedEmailContent
{
    /**
     * @param string       $title       Titre affiché en haut du message.
     * @param string       $preheader   Accroche cachée que les messageries montrent après l'objet (80 à 100 caractères).
     * @param list<string> $paragraphs  Une à trois phrases courtes, une par paragraphe.
     * @param string       $buttonLabel Libellé du bouton principal (« Choisir mon mot de passe »).
     * @param string       $buttonUrl   Adresse du bouton, aussi écrite en clair sous lui pour la copier.
     * @param string|null  $validity    Durée du lien, dite simplement (« Ce lien est valable 24 heures. »).
     * @param string       $note        Ce que le lecteur fait s'il n'est pas à l'origine de la demande.
     * @param string       $reason      Ligne discrète du pied : pourquoi il reçoit ce message.
     */
    public function __construct(
        public string $title,
        public string $preheader,
        public array $paragraphs,
        public string $buttonLabel,
        public string $buttonUrl,
        public ?string $validity = null,
        public ?BrandedEmailLink $secondary = null,
        public string $note = '',
        public string $reason = '',
    ) {
        self::assertWebAddress($buttonUrl);
    }

    /**
     * @throws \InvalidArgumentException si l'adresse n'est pas une adresse web (jamais `javascript:` ni `data:` dans un lien)
     */
    public static function assertWebAddress(string $url): void
    {
        if (1 !== preg_match('#^https?://[^\s"<>]+$#i', $url)) {
            throw new \InvalidArgumentException("Un lien d'e-mail doit être une adresse http ou https.");
        }
    }
}
