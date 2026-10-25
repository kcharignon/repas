<?php

namespace Repas\Voice\Domain\Model;

use stdClass;

/**
 * La réponse du webhook à Alexa (format « Custom Skill », version 1.0) : ce qu'elle dit, si la séance reste ouverte, et au besoin la
 * carte qui invite à lier le compte. Toujours du texte brut (`PlainText`) : jamais de SSML, jamais de contenu venu de la requête hors
 * de ce que `VoiceReplies` cite.
 *
 * Quatre formes seulement :
 * - `say()` : une phrase, la séance se ferme ;
 * - `ask()` : une phrase, la séance reste ouverte, et si l'utilisateur ne dit rien Alexa répète `$reprompt`. Une réponse qui laisse la
 *   séance ouverte écrit TOUJOURS `sessionAttributes` (ADR 0029 décision 11) : l'état du dialogue en cours, ou `{}` quand il n'y en a plus.
 *   Alexa ne rend à la requête suivante que ce qu'on lui a donné : sans la clé, l'état serait oublié ;
 * - `linkAccount()` : une phrase et la carte `LinkAccount` (l'application Alexa propose de lier le compte), la séance se ferme ;
 * - `silence()` : aucune parole (la fin d'une séance, `SessionEndedRequest`, n'en accepte pas).
 */
final readonly class AlexaResponse
{
    private function __construct(
        public ?string $speech,
        public bool $endsSession,
        public ?string $reprompt = null,
        public bool $linksAccount = false,
        public ?SpokenDialogueState $state = null,
    ) {
    }

    public static function say(string $speech): self
    {
        return new self($speech, true);
    }

    /**
     * @param ?SpokenDialogueState $state le dialogue en cours, rendu par Alexa à la requête suivante ; `null` : aucun
     */
    public static function ask(string $speech, string $reprompt, ?SpokenDialogueState $state = null): self
    {
        return new self($speech, false, $reprompt, false, $state);
    }

    public static function linkAccount(string $speech): self
    {
        return new self($speech, true, null, true);
    }

    public static function silence(): self
    {
        return new self(null, true);
    }

    /**
     * Le même texte, avec la séance ouverte ou fermée selon `$keepOpen` (la séance ouverte redemande `$reprompt` après un silence).
     */
    public static function reply(string $speech, bool $keepOpen, string $reprompt, ?SpokenDialogueState $state = null): self
    {
        return $keepOpen ? self::ask($speech, $reprompt, $state) : self::say($speech);
    }

    /**
     * @return array{version: string, sessionAttributes?: array<string, mixed>|stdClass, response: array<string, mixed>|stdClass}
     */
    public function toArray(): array
    {
        if ($this->speech === null) {
            // `response` doit rester un objet JSON, pas une liste vide
            return ['version' => '1.0', 'response' => new stdClass()];
        }

        $response = ['outputSpeech' => ['type' => 'PlainText', 'text' => $this->speech]];
        if ($this->reprompt !== null) {
            $response['reprompt'] = ['outputSpeech' => ['type' => 'PlainText', 'text' => $this->reprompt]];
        }
        if ($this->linksAccount) {
            $response['card'] = ['type' => 'LinkAccount'];
        }
        $response['shouldEndSession'] = $this->endsSession;

        if ($this->endsSession) {
            return ['version' => '1.0', 'response' => $response];
        }

        // `{}` et non `[]` : un objet JSON, comme Alexa l'attend
        $attributes = $this->state === null ? new stdClass() : [SpokenDialogueState::ATTRIBUTE => $this->state->toArray()];

        return ['version' => '1.0', 'sessionAttributes' => $attributes, 'response' => $response];
    }
}
