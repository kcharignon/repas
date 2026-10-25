<?php

namespace Repas\Voice\Domain\Model;

use Repas\Voice\Domain\Service\SpokenUnits;

/**
 * Ce que le webhook retient d'une requête d'Alexa DÉJÀ VÉRIFIÉE (ADR 0029, `AlexaRequestVerifier`) : son type, l'intention et ses
 * créneaux (`item`, `rank`, `department`, `unit` : le texte dit et l'identifiant de la valeur rapprochée ; `quantity` : un entier de 1 à 99, lu d'abord dans l'identifiant du type `QUANTITY`), l'état du dialogue de la séance
 * (`session.attributes.dialogue`, décision 11), la langue, si c'est la première requête de la séance, le jeton d'accès de la liaison de
 * compte et l'identifiant d'utilisateur Alexa. Rien d'autre : le reste du corps n'est jamais lu, gardé, ni journalisé (il contient la
 * voix transcrite).
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

    /** La quantité dite (« trois croquettes ») : un entier de 1 à 99. Au-delà, ou « zéro », on redemande. */
    public const int MIN_QUANTITY = 1;
    public const int MAX_QUANTITY = 99;

    /** Taille de la colonne `voice_link.alexa_user_id`. */
    public const int MAX_ALEXA_USER_ID_LENGTH = 255;

    /** Un de nos jetons fait 43 caractères : un jeton bien plus long n'est pas le nôtre, il n'ira même pas jusqu'à la base. */
    public const int MAX_ACCESS_TOKEN_LENGTH = 2048;

    private function __construct(
        public string $type,
        public ?string $intentName,
        public ?string $phrase,
        public ?SlotValue $rank,
        public ?SlotValue $department,
        public ?SlotValue $unit,
        /** La quantité dite, entière et de 1 à 99 ; `null` si elle manque OU si elle est inutilisable (voir `hasUnusableQuantity`). */
        public ?int $quantity,
        /** Le créneau `quantity` portait une valeur lisible (Alexa a entendu quelque chose), utilisable ou non. */
        public bool $quantityHeard,
        public ?SpokenDialogueState $dialogueState,
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
        $spokenQuantity = self::valueAt($data, ['request', 'intent', 'slots', 'quantity', 'value']);
        // Le type `QUANTITY` du modèle a pour identifiant le nombre lui-même (« deux » et « 2 » rendent « 2 ») : il passe avant le texte dit.
        // Un vrai nombre JSON est lu comme du texte (tolérance)
        $quantityText = $isIntent
            ? SlotValue::fromDecoded(self::valueAt($data, ['request', 'intent', 'slots', 'quantity']))->id
                ?? SlotValue::cleanText(is_int($spokenQuantity) || is_float($spokenQuantity) ? (string) $spokenQuantity : $spokenQuantity)
            : null;

        return new self(
            type: $type ?? '',
            intentName: $isIntent ? self::stringAt($data, ['request', 'intent', 'name']) : null,
            phrase: $isIntent ? self::cleanPhrase(self::stringAt($data, ['request', 'intent', 'slots', 'item', 'value'])) : null,
            rank: $isIntent ? SlotValue::fromDecoded(self::valueAt($data, ['request', 'intent', 'slots', 'rank'])) : null,
            department: $isIntent ? SlotValue::fromDecoded(self::valueAt($data, ['request', 'intent', 'slots', 'department'])) : null,
            unit: $isIntent ? SlotValue::fromDecoded(self::valueAt($data, ['request', 'intent', 'slots', 'unit'])) : null,
            quantity: $isIntent ? self::quantityIn($quantityText) : null,
            quantityHeard: $isIntent && $quantityText !== null,
            dialogueState: $isIntent ? SpokenDialogueState::fromArray(self::valueAt($data, ['session', 'attributes', SpokenDialogueState::ATTRIBUTE])) : null,
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

    /**
     * Alexa a entendu une quantité que nous ne savons pas écrire : un décimal, un négatif, zéro, plus de 99, un mot (« beaucoup »). Rien
     * n'est écrit, on redemande (`VoiceReplies::HOW_MANY`).
     */
    public function hasUnusableQuantity(): bool
    {
        return $this->quantityHeard && $this->quantity === null;
    }

    /**
     * L'unité dite (`unit`), d'après l'identifiant de la résolution d'entité ou, à défaut, le texte entendu rapproché du catalogue des unités.
     */
    public function unitSlug(): ?string
    {
        return $this->unit->id ?? new SpokenUnits()->slugOf($this->unit?->text);
    }

    public function hasSupportedLocale(): bool
    {
        return $this->locale === self::SUPPORTED_LOCALE;
    }

    /**
     * La séance est déjà ouverte : ce n'est pas la première requête de la séance (« Alexa, ouvre Jarvis », puis « du pain »).
     */
    public function isInOpenSession(): bool
    {
        return !$this->isFirstOfSession;
    }

    /**
     * Tout ce qu'Alexa a entendu dans les créneaux de l'intention, sans doublon : de quoi rapprocher une réponse brève d'une proposition quelle
     * que soit l'intention qu'Alexa a choisie.
     *
     * @return list<string>
     */
    public function heardTexts(): array
    {
        $texts = array_filter([$this->phrase, $this->department?->text, $this->unit?->text, $this->rank?->text], static fn(?string $text): bool => $text !== null);

        return array_values(array_unique($texts));
    }

    /**
     * La phrase du créneau `item` : espaces réduits, caractères de contrôle retirés. `null` si elle manque, est vide, ne contient aucune
     * lettre ni chiffre (Alexa écrit « ? » quand elle n'a rien compris) ou dépasse `MAX_PHRASE_LENGTH`.
     */
    private static function cleanPhrase(?string $value): ?string
    {
        return SlotValue::cleanText($value);
    }

    /**
     * Les chiffres seuls, de 1 à 99. Alexa écrit un nombre dit (« trois ») en chiffres (`AMAZON.NUMBER`) ; « 1.5 », « -2 », « 0 », « 100 » et
     * tout texte donnent `null`.
     */
    private static function quantityIn(?string $text): ?int
    {
        if ($text === null || preg_match('/^\d{1,3}$/', $text) !== 1) {
            return null;
        }
        $quantity = (int) $text;

        return $quantity >= self::MIN_QUANTITY && $quantity <= self::MAX_QUANTITY ? $quantity : null;
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
