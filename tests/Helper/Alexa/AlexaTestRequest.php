<?php

namespace Repas\Tests\Helper\Alexa;


use DateTimeImmutable;
use DateTimeZone;

/**
 * Une requête d'Alexa synthétique, à la forme de celles d'un skill personnalisé (`LaunchRequest`, `IntentRequest`,
 * `SessionEndedRequest`) : jamais un vrai identifiant, jamais un vrai jeton. Immuable : chaque `with…` rend une copie.
 *
 *     AlexaTestRequest::addItem('du pain')->withAccessToken($token)->inOpenSession()->toJson();
 */
final readonly class AlexaTestRequest
{
    /** L'identifiant du skill de test (celui que les tests de bout en bout donnent au vérificateur). */
    public const string SKILL_ID = 'amzn1.ask.skill.00000000-test-0000-0000-000000000001';
    public const string USER_ID = 'amzn1.ask.account.TESTUSER';
    public const string CERT_CHAIN_URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert-test.pem';

    /**
     * @param array<string, mixed> $slots
     */
    private function __construct(
        private string $type,
        private ?string $intentName,
        private array $slots,
        private string $locale,
        private bool $isNewSession,
        private ?string $accessToken,
        private ?string $alexaUserId,
        private string $applicationId,
        private ?DateTimeImmutable $at,
        private bool $tokenInContext,
    ) {
    }

    public static function launch(): self
    {
        return self::of('LaunchRequest', null, []);
    }

    /** « ajoute du pain » : l'intention `AddItemIntent` et son créneau `item`. */
    public static function addItem(?string $item = 'du pain'): self
    {
        return self::of('IntentRequest', 'AddItemIntent', $item === null ? ['item' => ['name' => 'item']] : ['item' => ['name' => 'item', 'value' => $item]]);
    }

    public static function intent(string $name): self
    {
        return self::of('IntentRequest', $name, []);
    }

    public static function sessionEnded(): self
    {
        return self::of('SessionEndedRequest', null, []);
    }

    /** Un type de requête que le skill ne déclare pas (`System.ExceptionEncountered`, `AudioPlayer.PlaybackStarted`…). */
    public static function ofType(string $type): self
    {
        return self::of($type, null, []);
    }

    public function withAccessToken(?string $accessToken): self
    {
        return $this->copy(accessToken: $accessToken, withAccessToken: true);
    }

    /** Le jeton arrive dans `context.System.user` et non dans `session.user` (une requête sans séance). */
    public function withTokenInContextOnly(): self
    {
        return $this->copy(tokenInContext: true);
    }

    public function withAlexaUserId(?string $alexaUserId): self
    {
        return $this->copy(alexaUserId: $alexaUserId, withAlexaUserId: true);
    }

    public function inOpenSession(): self
    {
        return $this->copy(isNewSession: false);
    }

    public function withLocale(string $locale): self
    {
        return $this->copy(locale: $locale);
    }

    public function withApplicationId(string $applicationId): self
    {
        return $this->copy(applicationId: $applicationId);
    }

    public function at(DateTimeImmutable $at): self
    {
        return $this->copy(at: $at);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $at = ($this->at ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $user = ['userId' => $this->alexaUserId];
        if ($this->accessToken !== null) {
            $user['accessToken'] = $this->accessToken;
        }
        if ($this->alexaUserId === null) {
            unset($user['userId']);
        }

        $request = ['type' => $this->type, 'requestId' => 'amzn1.echo-api.request.00000000-test', 'timestamp' => $at, 'locale' => $this->locale];
        if ($this->intentName !== null) {
            $request['intent'] = ['name' => $this->intentName, 'confirmationStatus' => 'NONE', 'slots' => $this->slots];
        }

        $body = ['version' => '1.0'];
        if (!$this->tokenInContext) {
            $body['session'] = [
                'new' => $this->isNewSession,
                'sessionId' => 'amzn1.echo-api.session.00000000-test',
                'application' => ['applicationId' => $this->applicationId],
                'user' => $user,
            ];
        }
        $body['context'] = ['System' => [
            'application' => ['applicationId' => $this->applicationId],
            'user' => $user,
        ]];
        $body['request'] = $request;

        return $body;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $slots
     */
    private static function of(string $type, ?string $intentName, array $slots): self
    {
        return new self($type, $intentName, $slots, 'fr-FR', true, null, self::USER_ID, self::SKILL_ID, null, false);
    }

    private function copy(
        ?string $accessToken = null,
        bool $withAccessToken = false,
        ?string $alexaUserId = null,
        bool $withAlexaUserId = false,
        ?bool $isNewSession = null,
        ?string $locale = null,
        ?string $applicationId = null,
        ?DateTimeImmutable $at = null,
        ?bool $tokenInContext = null,
    ): self {
        return new self(
            $this->type,
            $this->intentName,
            $this->slots,
            $locale ?? $this->locale,
            $isNewSession ?? $this->isNewSession,
            $withAccessToken ? $accessToken : $this->accessToken,
            $withAlexaUserId ? $alexaUserId : $this->alexaUserId,
            $applicationId ?? $this->applicationId,
            $at ?? $this->at,
            $tokenInContext ?? $this->tokenInContext,
        );
    }
}
