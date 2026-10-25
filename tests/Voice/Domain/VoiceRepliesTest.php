<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Repas\Voice\Domain\Model\VoiceReplies;

/**
 * Le catalogue de ce qu'Alexa dit (VOX-204, skill `repas-po`, `docs/drive/principes-produit.md`) : la voix du produit. Un proche doit
 * pouvoir dire chaque phrase à voix haute : tutoiement, une à deux phrases, 15 mots au plus par phrase, aucun jargon, aucun
 * avertissement, aucun point d'exclamation.
 */
class VoiceRepliesTest extends TestCase
{
    /** Mots qu'un proche ne dit pas (glossaire) ; « ingrédient » n'est permis que dans « tes ingrédients ». */
    private const array JARGON = [
        'slug', 'api', 'json', 'webhook', 'skill', 'token', 'jeton', 'erreur', 'exception', 'serveur', 'requête', 'liaison', 'authentification',
        'identifiant', 'session', 'système', 'base de données', 'code', 'plan', 'agent', 'passage', 'fallback', 'intent', 'intention',
    ];

    public function testEveryReplyOfTheCatalogueIsAtMostTwoShortSentences(): void
    {
        foreach ($this->catalogue() as $name => $text) {
            // When
            $sentences = $this->sentences($text);

            // Then
            $this->assertLessThanOrEqual(2, count($sentences), "$name : « $text » fait plus de deux phrases");
            foreach ($sentences as $sentence) {
                $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), "$name : « $sentence » fait plus de 15 mots");
            }
        }
    }

    public function testNoReplyUsesJargonOrAWarningTone(): void
    {
        foreach ($this->catalogue() as $name => $text) {
            $lower = mb_strtolower($text);

            // « ingrédient » : seulement dans « tes ingrédients »
            $withoutAllowedWord = str_replace('tes ingrédients', '', $lower);
            $this->assertStringNotContainsString('ingrédient', $withoutAllowedWord, "$name : « $text »");

            foreach (self::JARGON as $word) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/u', $lower, "$name : « $text » dit « $word »");
            }
            $this->assertStringNotContainsString('!', $text, "$name : pas de point d'exclamation");
            $this->assertDoesNotMatchRegularExpression('/\p{Lu}{2,}/u', $text, "$name : pas de majuscules criées");
        }
    }

    public function testEveryReplyAddressesTheUserAsATutoyedFriend(): void
    {
        foreach ($this->catalogue() as $name => $text) {
            $lower = mb_strtolower($text);

            // Ni « vous », « votre », « vos », ni impératif en « -ez »
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(vous|votre|vos)(?![\p{L}])/u', $lower, "$name : « $text »");
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])\p{L}+ez(?![\p{L}])/u', $lower, "$name : « $text » a un impératif en -ez");
        }
    }

    public function testTheSpokenAnswersAreTheOnesTheTicketGives(): void
    {
        $this->assertSame("C'est noté : tomate.", VoiceReplies::added('tomate', 'fruits et légumes', true, false, false));
        $this->assertSame("J'ai mis tomate, fruits et légumes.", VoiceReplies::added('tomate', 'fruits et légumes', false, false, false));
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", VoiceReplies::added('pain', 'boulangerie', true, true, false));
        $this->assertSame("J'ai commencé une nouvelle liste avec pain. Ta liste précédente est partie au drive.", VoiceReplies::added('pain', 'boulangerie', true, true, true));
        $this->assertSame("J'ai commencé une nouvelle liste avec tomate, fruits et légumes.", VoiceReplies::added('tomate', 'fruits et légumes', false, true, false));
        $this->assertSame('Je ne trouve pas xyz dans tes ingrédients.', VoiceReplies::notFound('xyz'));
        $this->assertSame("C'est noté : pain. Autre chose ?", VoiceReplies::withInvitation("C'est noté : pain."));
        $this->assertSame('Que veux-tu ajouter ?', $this->said('LAUNCH'));
        $this->assertSame('À bientôt.', $this->said('GOODBYE'));
        $this->assertSame('Dis par exemple : ajoute du pain.', $this->said('FALLBACK'));
        $this->assertSame("Ouvre l'application Alexa pour lier ton compte Repas.", $this->said('LINK_ACCOUNT'));
        $this->assertSame('Un instant, réessaie dans une minute.', $this->said('SLOW_DOWN'));
    }

    public function testAPreviousSentListIsOnlyMentionedWhenAListWasCreated(): void
    {
        $this->assertStringNotContainsString('drive', VoiceReplies::added('pain', 'boulangerie', true, false, true));
    }

    public function testEveryAssembledAnswerStaysShort(): void
    {
        foreach ([true, false] as $isSure) {
            foreach ([true, false] as $listCreated) {
                foreach ([true, false] as $previousListSent) {
                    // When
                    $answer = VoiceReplies::added('farine de blé', 'épicerie', $isSure, $listCreated, $previousListSent);
                    $inSession = VoiceReplies::withInvitation($answer);

                    // Then : deux phrases d'information au plus ; en séance, l'invitation à continuer s'y ajoute et rien d'autre
                    $this->assertLessThanOrEqual(2, count($this->sentences($answer)), $answer);
                    $this->assertLessThanOrEqual(3, count($this->sentences($inSession)), $inSession);
                    $this->assertStringEndsWith(' Autre chose ?', $inSession);
                    foreach ($this->sentences($inSession) as $sentence) {
                        $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), $sentence);
                    }
                }
            }
        }
    }

    public function testANameIsLowercasedCleanedAndCutBeforeItIsSpoken(): void
    {
        $this->assertSame('crème fraîche', VoiceReplies::speakable("  Crème\t FRAÎCHE\n"));
        $this->assertSame('du pain', VoiceReplies::speakable("du\u{0000}pain"));
        $this->assertSame('', VoiceReplies::speakable(" \n "));
        $cut = VoiceReplies::speakable(str_repeat('a', 59) . ' ' . str_repeat('b', 40));
        $this->assertSame(str_repeat('a', 59), $cut);
        $this->assertSame(60, mb_strlen(VoiceReplies::speakable(str_repeat('é', 200))));
    }

    public function testAPhraseTheUserSaidIsSpokenBackSafely(): void
    {
        $answer = VoiceReplies::notFound("<speak>XYZ</speak>\u{0007}" . str_repeat('z', 200));

        $this->assertLessThanOrEqual(60 + mb_strlen(VoiceReplies::NOT_FOUND), mb_strlen($answer));
        $this->assertStringNotContainsString("\u{0007}", $answer);
        $this->assertStringStartsWith('Je ne trouve pas <speak>xyz</speak>', $answer, 'texte brut : la balise n\'est que du texte pour PlainText, jamais du SSML');
    }

    private function said(string $constant): string
    {
        return constant(VoiceReplies::class . '::' . $constant);
    }

    /**
     * Les phrases fixes et les modèles de phrase (leurs trous remplis par un nom de deux mots), nommés par leur constante.
     *
     * @return array<string, string>
     */
    private function catalogue(): array
    {
        $catalogue = [];
        foreach (new ReflectionClass(VoiceReplies::class)->getReflectionConstants() as $constant) {
            $value = $constant->getValue();
            if (is_string($value) && $constant->isPublic()) {
                $catalogue[$constant->getName()] = str_replace('%s', 'farine complète', $value);
            }
        }
        $this->assertGreaterThanOrEqual(14, count($catalogue), 'le catalogue n\'est plus lu');

        return $catalogue;
    }

    /**
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        return preg_split('/(?<=[.!?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    }
}
