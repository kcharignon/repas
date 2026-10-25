<?php

namespace Repas\Voice\Domain\Model;


/**
 * Ce que le webhook retient d'une requête d'Alexa DÉJÀ VÉRIFIÉE (ADR 0029, `AlexaRequestVerifier`) : son type, l'intention et la phrase
 * dite, la langue, si c'est la première requête de la séance, le jeton d'accès de la liaison de compte et l'identifiant d'utilisateur
 * Alexa. Rien d'autre : le reste du corps n'est jamais lu, gardé, ni journalisé (il contient la voix transcrite).
 *
 * La lecture est TOLÉRANTE : un champ absent ou d'une forme inattendue donne `null` (ou le défaut le plus prudent), jamais une exception,
 * pour qu'une requête authentique mais étrange reçoive une réponse vocale polie plutôt qu'une erreur.
 */
final readonly class AlexaRequest
{
    public const string LAUNCH = 'LaunchRequest';
    public const string INTENT = 'IntentRequest';
    public const string SESSION_ENDED = 'SessionEndedRequest';

    /** La seule langue servie (modèle d'interaction `fr-FR`). */
    public const string SUPPORTED_LOCALE = 'fr-FR';

    /** Un nom d'ingrédient dit à voix haute tient en quelques mots : au-delà, la reconnaissance a déraillé, on redemande. */
    public const int MAX_PHRASE_LENGTH = 100;

    /** Taille de la colonne `voice_link.alexa_user_id`. */
    public const int MAX_ALEXA_USER_ID_LENGTH = 255;

    /** Un de nos jetons fait 43 caractères : un jeton bien plus long n'est pas le nôtre, il n'ira même pas jusqu'à la base. */
    public const int MAX_ACCESS_TOKEN_LENGTH = 2048;

    private function __construct(
        public string $type,
        public ?string $intentName,
        public ?string $phrase,
        public ?string $locale,
        public bool $isFirstOfSession,
        public ?string $accessToken,
        public ?string $alexaUserId,
    ) {
    }

    public static function fromDecoded(mixed $decoded): self
    {
        $data = is_array($decoded) ? $decoded : [];

        $type = self::stringAt($data, ['request', 'type']);
        $isIntent = $type === self::INTENT;

        return new self(
            type: $type ?? '',
            intentName: $isIntent ? self::stringAt($data, ['request', 'intent', 'name']) : null,
            phrase: $isIntent ? self::cleanPhrase(self::stringAt($data, ['request', 'intent', 'slots', 'item', 'value'])) : null,
            locale: self::stringAt($data, ['request', 'locale']),
            // Une séance déjà ouverte le dit expressément (`false`) ; absent ou autre chose : appel direct, la séance sera fermée
            isFirstOfSession: self::valueAt($data, ['session', 'new']) !== false,
            accessToken: self::boundedString(
                self::stringAt($data, ['session', 'user', 'accessToken']) ?? self::stringAt($data, ['context', 'System', 'user', 'accessToken']),
                self::MAX_ACCESS_TOKEN_LENGTH,
            ),
            alexaUserId: self::boundedString(
                self::stringAt($data, ['session', 'user', 'userId']) ?? self::stringAt($data, ['context', 'System', 'user', 'userId']),
                self::MAX_ALEXA_USER_ID_LENGTH,
            ),
        );
    }

    /**
     * L'intention connue du skill, `null` pour une autre (une intention d'Amazon que le modèle ne déclare pas) ou pour une requête qui
     * n'est pas une intention.
     */
    public function intent(): ?AlexaIntent
    {
        return $this->intentName === null ? null : AlexaIntent::tryFrom($this->intentName);
    }

    public function hasSupportedLocale(): bool
    {
        return $this->locale === self::SUPPORTED_LOCALE;
    }

    /**
     * La séance est déjà ouverte : ce n'est pas la première requête de la séance (« Alexa, ouvre mes courses », puis « du pain »).
     */
    public function isInOpenSession(): bool
    {
        return !$this->isFirstOfSession;
    }

    /**
     * La phrase du créneau `item` : espaces réduits, caractères de contrôle retirés. `null` si elle manque, est vide, ne contient aucune
     * lettre ni chiffre (Alexa écrit « ? » quand elle n'a rien compris) ou dépasse `MAX_PHRASE_LENGTH`.
     */
    private static function cleanPhrase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $phrase = preg_replace(['/\p{C}+/u', '/\s+/u'], ' ', $value);
        $phrase = $phrase === null ? '' : trim($phrase);
        if ($phrase === '' || mb_strlen($phrase) > self::MAX_PHRASE_LENGTH || preg_match('/[\p{L}\p{N}]/u', $phrase) !== 1) {
            return null;
        }

        return $phrase;
    }

    private static function boundedString(?string $value, int $maxLength): ?string
    {
        return $value === null || $value === '' || mb_strlen($value) > $maxLength ? null : $value;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     */
    private static function stringAt(array $data, array $path): ?string
    {
        $value = self::valueAt($data, $path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     */
    private static function valueAt(array $data, array $path): mixed
    {
        $node = $data;
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }
}
