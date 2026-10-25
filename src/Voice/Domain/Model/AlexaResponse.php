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
 * - `ask()` : une phrase, la séance reste ouverte, et si l'utilisateur ne dit rien Alexa répète `$reprompt` ;
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
    ) {
    }

    public static function say(string $speech): self
    {
        return new self($speech, true);
    }

    public static function ask(string $speech, string $reprompt): self
    {
        return new self($speech, false, $reprompt);
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
    public static function reply(string $speech, bool $keepOpen, string $reprompt): self
    {
        return $keepOpen ? self::ask($speech, $reprompt) : self::say($speech);
    }

    /**
     * @return array{version: string, response: array<string, mixed>|stdClass}
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

        return ['version' => '1.0', 'response' => $response];
    }
}
