<?php

namespace Repas\Shared\Infrastructure\Mailer;


/**
 * Lien secondaire d'un e-mail de marque : une phrase d'introduction, le libellé du lien et son adresse
 * (« Mot de passe oublié ? » / « Choisis-en un nouveau »).
 */
final readonly class BrandedEmailLink
{
    public function __construct(
        public string $intro,
        public string $label,
        public string $url,
    ) {
        BrandedEmailContent::assertWebAddress($url);
    }
}
