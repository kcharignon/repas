<?php

namespace Repas\User\Infrastructure\Http\Api\RegisterNewUser;


use OpenApi\Attributes as OA;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Http\EmailRecipientPayload;
use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de l'inscription : l'adresse suffit (ADR 0020 amendé). Le mot de passe se choisit après le clic sur le lien reçu par email.
 */
final readonly class RegisterNewUserPayload implements EmailRecipientPayload
{
    private const string SERVING = 'Le nombre de personnes doit être un nombre entier de 1 à 100.';

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Adresse email, unique : sert d'identifiant de connexion", example: 'alexiane@example.com')]
        public string $email,
        // `mixed` : la valeur reçue est jugée telle quelle (un entier JSON, rien d'autre) ; un type `int` ferait convertir une décimale par PHP avant tout contrôle
        #[Assert\Sequentially([
            new Assert\NotNull(message: self::SERVING),
            new Assert\Type(type: 'integer', message: self::SERVING),
            new Assert\Range(min: 1, max: 100, notInRangeMessage: self::SERVING),
        ])]
        #[OA\Property(description: "Nombre de personnes proposé par défaut pour une recette ; 4 si le champ est absent", type: 'integer', example: 4, minimum: 1, maximum: 100)]
        public mixed $defaultServing = User::DEFAULT_SERVING,
        // Champ des anciens clients (l'ancien formulaire du site, l'application déjà installée) : accepté pour ne rien casser, jamais lu, jamais
        // validé, jamais stocké. Pas de contrainte, de quelque type que ce soit : un ancien client n'a pas à être refusé pour cette valeur.
        #[OA\Property(description: "Ignoré : le mot de passe se choisit après le clic sur le lien reçu par email. Accepté (quelle que soit sa valeur) pour les anciens clients, jamais stocké.", type: 'string', deprecated: true)]
        #[SensitiveParameter]
        public mixed $password = null,
    ) {
    }

    public function recipientEmail(): string
    {
        return $this->email;
    }
}
