<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Repas\Voice\Domain\Model\AddedLine;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\ListLine;
use Repas\Voice\Domain\Model\RemovedLine;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;

/**
 * Le catalogue de ce qu'Alexa dit (VOX-204, VOX-303, skill `repas-po`, `docs/drive/principes-produit.md`) : la voix du produit. Un proche doit
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

    /**
     * La seule exception à « deux phrases » (ADR 0029 décision 11) : la question « de quoi tu parles ? » d'un produit inconnu dit ce qui n'est pas
     * connu, les propositions et l'issue « un nouveau produit » ; la question de l'unité (exemples, puis « Dis lesquelles pour les entendre »).
     * Trois phrases, chacune de 15 mots au plus ; pas une de plus. La réponse à « lesquels ? » a son propre test
     * (`testTheAnswerToWhichOnesEnumeratesTheWholeCatalogueInTwoSentences`).
     */
    private const array THREE_SENTENCES = ['ASK_WHICH', 'ASK_WHICH_AGAIN', 'ASK_UNIT'];

    public function testEveryReplyOfTheCatalogueIsAtMostTwoShortSentences(): void
    {
        foreach ($this->catalogue() as $name => $text) {
            // When
            $sentences = $this->sentences($text);

            // Then
            $limit = in_array($name, self::THREE_SENTENCES, true) ? 3 : 2;
            $this->assertLessThanOrEqual($limit, count($sentences), "$name : « $text » fait plus de $limit phrases");
            foreach ($sentences as $sentence) {
                $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), "$name : « $sentence » fait plus de 15 mots");
            }
        }
    }

    public function testNoReplyEverAsksForMoreAfterAnAnswer(): void
    {
        foreach ($this->catalogue() as $name => $text) {
            $this->assertStringNotContainsStringIgnoringCase('autre chose', $text, "$name : pas de relance, jamais");
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
        $this->assertSame('Que veux-tu ajouter ?', $this->said('LAUNCH'));
        $this->assertSame('À bientôt.', $this->said('GOODBYE'));
        $this->assertSame('Dis par exemple : ajoute du pain.', $this->said('FALLBACK'));
        $this->assertSame("Ouvre l'application Alexa pour lier ton compte Repas.", $this->said('LINK_ACCOUNT'));
        $this->assertSame('Un instant, réessaie dans une minute.', $this->said('SLOW_DOWN'));
    }

    public function testTheSpokenAnswersOfTheDialogueOfAnUnknownProductAreTheOnesTheOwnerGives(): void
    {
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $this->said('ASK_DEPARTMENT'));
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.', $this->said('ASK_UNIT'));
        $this->assertSame("D'accord, je ne crée rien.", $this->said('DECLINED'));
        $this->assertSame("C'est noté : persil. Je m'en souviendrai.", VoiceReplies::remembered('persil', false, false));
        $this->assertSame('Je ne connais pas ciboulette. Tu veux dire persil, basilic ou coriandre ? Ou un nouveau produit ?', VoiceReplies::unknown('ciboulette', ['persil', 'basilic', 'coriandre']));
        $this->assertSame('Je ne connais pas ciboulette. Tu veux dire persil ou basilic ? Ou un nouveau produit ?', VoiceReplies::unknown('ciboulette', ['persil', 'basilic']));
        $this->assertSame('Je ne connais pas ciboulette. Tu veux dire persil ? Ou un nouveau produit ?', VoiceReplies::unknown('ciboulette', ['persil']));
        $this->assertSame('Je ne connais pas ciboulette. Je le crée ?', VoiceReplies::unknown('ciboulette', []));
    }

    public function testTheAnswerToWhichOnesEnumeratesTheWholeCatalogueInTwoSentences(): void
    {
        // Given : les 21 rayons et 25 unités du catalogue (noms réels, avec un « & »), plus longs que 15 mots
        $departments = array_map(static fn(int $i): string => 'Rayon ' . $i . ' & cie', range(1, 21));
        $units = array_map(static fn(int $i): string => 'unité ' . $i, range(1, 25));
        $base = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->askingDepartment();
        $unitState = $base->withDepartment(new DialogueOption('d', 'boucherie'));

        // When
        $rayons = VoiceReplies::choices($base, $departments);
        $unites = VoiceReplies::choices($unitState, $units);

        // Then : deux phrases ; l'énumération, seule exemptée de « 15 mots au plus » (on écoute la liste pour choisir), puis « Lequel ? » / « Laquelle ? »
        foreach ([[$rayons, 'Les rayons : ', 'Lequel ?', 21], [$unites, 'Les unités : ', 'Laquelle ?', 25]] as [$reply, $start, $end, $count]) {
            $this->assertNotNull($reply);
            $sentences = $this->sentences($reply);
            $this->assertCount(2, $sentences, $reply);
            $this->assertStringStartsWith($start, $sentences[0]);
            $this->assertSame($end, $sentences[1]);
            $this->assertCount($count, explode(', ', substr($sentences[0], strlen($start), -1)), 'tous les noms, séparés par des virgules');
            $this->assertStringNotContainsString('&', $reply);
            $this->assertStringNotContainsString('%', $reply);
        }
        $this->assertStringContainsString('rayon 1 et cie', $rayons);
        $this->assertSame('Les rayons : fruit et légume, fromage. Lequel ?', VoiceReplies::choices($base, ['Fruit & légume', 'fromage']));
        $this->assertSame('Les unités : pièce, kilo. Laquelle ?', VoiceReplies::choices($unitState, ['pièce', 'kilo']));
    }

    public function testThereIsNothingToEnumerateForTheOtherStepsOrAnEmptyCatalogue(): void
    {
        $choose = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('p', 'persil')], 'ciboulette');
        $department = $choose->askingDepartment();

        $this->assertNull(VoiceReplies::choices($choose, ['persil']));
        $this->assertNull(VoiceReplies::choices($department->withDepartment(new DialogueOption('d', 'boucherie'))->withUnit(new DialogueOption('u', 'kilo')), ['kilo']));
        $this->assertNull(VoiceReplies::choices($department, []));
        $this->assertNull(VoiceReplies::choices($department, [' ', "\n"]));
    }

    public function testTheQuestionToTellIngredientsApartCitesTheDepartmentAndTheNameWhenTheyDiffer(): void
    {
        $same = [new DialogueOption('a', 'Tomate', 'fruit-et-legume', 'Fruit & légume'), new DialogueOption('b', 'Tomate', 'surgele', 'Surgelé')];
        $three = [...$same, new DialogueOption('c', 'tomate', 'conserve', 'Conserve')];
        $names = [new DialogueOption('a', 'Tomate', 'surgele', 'Surgelé'), new DialogueOption('b', 'Tomates pelées', 'conserve', 'Conserve')];

        $this->assertSame('Tomate : en fruit et légume ou en surgelé ?', VoiceReplies::tieQuestion($same));
        $this->assertSame('Tomate : en fruit et légume, en surgelé ou en conserve ?', VoiceReplies::tieQuestion($three));
        $this->assertSame('Tu veux dire tomate en surgelé ou tomates pelées en conserve ?', VoiceReplies::tieQuestion($names));
        $state = SpokenDialogueState::choose('des tomates', $same, 'tomate');
        $this->assertSame('Tomate : en fruit et légume ou en surgelé ?', VoiceReplies::question($state));
        $this->assertSame("Je n'ai pas compris. Tomate : en fruit et légume ou en surgelé ?", VoiceReplies::notUnderstood($state));
        $this->assertSame(VoiceReplies::question($state), VoiceReplies::whichAgain($state));
        foreach ([VoiceReplies::tieQuestion($same), VoiceReplies::tieQuestion($three), VoiceReplies::tieQuestion($names), VoiceReplies::notUnderstood($state)] as $reply) {
            $this->assertLessThanOrEqual(2, count($this->sentences($reply)), $reply);
            $this->assertStringNotContainsString('nouveau produit', $reply);
            foreach ($this->sentences($reply) as $sentence) {
                $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), $sentence);
            }
        }
    }

    public function testChoosingByDepartmentRepeatsTheDepartmentAndPromisesToRemember(): void
    {
        $this->assertSame("C'est noté : tomate, fruit et légume. Je m'en souviendrai.", VoiceReplies::rememberedIn('Tomate', 'Fruit & légume', false, false));
        $this->assertSame("J'ai commencé une nouvelle liste avec tomate, fruit et légume. Je m'en souviendrai.", VoiceReplies::rememberedIn('Tomate', 'Fruit & légume', true, false));
        $this->assertSame("J'ai commencé une nouvelle liste avec tomate, fruit et légume. Ta liste précédente est partie au drive.", VoiceReplies::rememberedIn('Tomate', 'Fruit & légume', true, true));
        $this->assertStringNotContainsString('drive', VoiceReplies::rememberedIn('Tomate', 'Fruit & légume', false, true));
    }

    public function testTheQuestionsOfTheDepartmentAndTheUnitTellThatTheyCanBeListed(): void
    {
        $this->assertStringContainsString('Dis lesquels pour les entendre.', VoiceReplies::ASK_DEPARTMENT);
        $this->assertStringContainsString('Dis lesquelles pour les entendre.', VoiceReplies::ASK_UNIT);

        // Une unité incomprise : « Je n'ai pas compris. » puis les trois phrases de la question, soit quatre phrases courtes au plus
        $unit = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->askingDepartment()->withDepartment(new DialogueOption('d', 'boucherie'));
        $sentences = $this->sentences(VoiceReplies::notUnderstood($unit));
        $this->assertCount(4, $sentences);
        foreach ($sentences as $sentence) {
            $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), $sentence);
        }
    }

    public function testACreatedProductRepeatsItsDepartmentAndStaysShortWithRealisticNames(): void
    {
        foreach ([false, true] as $listCreated) {
            foreach ([false, true] as $previousListSent) {
                $reply = VoiceReplies::created('tomates cerises pelées', 'Fruit & légume', $listCreated, $previousListSent);

                $this->assertStringContainsString('rayon fruit et légume', $reply);
                $this->assertLessThanOrEqual(3, count($this->sentences($reply)), $reply);
                foreach ($this->sentences($reply) as $sentence) {
                    $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), $sentence);
                }
            }
        }
    }

    public function testTheQuestionOfEachStepIsRepeatedInFewWords(): void
    {
        // Given
        $choose = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('p', 'persil'), new DialogueOption('b', 'basilic')], 'ciboulette');
        $none = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette');
        $department = $none->askingDepartment();
        $unit = $department->withDepartment(new DialogueOption('fruit-et-legume', 'Fruit & légume'));
        $confirm = $unit->withUnit(new DialogueOption('unite', 'unité'));

        // Then
        $this->assertSame('Tu veux dire persil ou basilic ? Ou un nouveau produit ?', VoiceReplies::question($choose));
        $this->assertSame('Je ne connais pas ciboulette. Je le crée ?', VoiceReplies::question($none));
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', VoiceReplies::question($department));
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.', VoiceReplies::question($unit));
        $this->assertSame("Je crée ciboulette, fruit et légume, unité. C'est bon ?", VoiceReplies::question($confirm), '« & » se dit « et », les majuscules du catalogue tombent');
        $this->assertSame("Je n'ai pas compris. Dans quel rayon ? Dis lesquels pour les entendre.", VoiceReplies::notUnderstood($department));
        $this->assertSame('Lequel ? persil ou basilic. Ou un nouveau produit ?', VoiceReplies::whichAgain($choose));
    }

    public function testEveryAssembledReplyOfTheDialogueStaysShortWithRealisticNames(): void
    {
        // Given : des noms d'un à trois mots, comme ceux du catalogue
        $options = array_map(
            static fn(string $name): DialogueOption => new DialogueOption('s', $name),
            ['pâte feuilletée', 'tomates cerises pelées', 'crème fraîche épaisse'],
        );
        $state = SpokenDialogueState::choose('de la ciboulette fraîche', $options, 'ciboulette fraîche');
        $confirm = $state->askingDepartment()
            ->withDepartment(new DialogueOption('d', 'fruit & légume'))
            ->withUnit(new DialogueOption('u', 'cuillère à soupe'));

        $replies = [
            'unknown' => VoiceReplies::unknown('ciboulette fraîche', array_map(static fn(DialogueOption $option): string => $option->name, $options)),
            'unknown sans proposition' => VoiceReplies::unknown('ciboulette fraîche', []),
            'question choisir' => VoiceReplies::question($state),
            'question confirmer' => VoiceReplies::question($confirm),
            'pas compris choisir' => VoiceReplies::notUnderstood($state),
            'pas compris confirmer' => VoiceReplies::notUnderstood($confirm),
            'lequel' => VoiceReplies::whichAgain($state),
            'abandon' => VoiceReplies::DECLINED,
            'renoncement' => VoiceReplies::GIVE_UP,
        ];
        foreach ([false, true] as $listCreated) {
            foreach ([false, true] as $previousListSent) {
                $replies["retenu $listCreated $previousListSent"] = VoiceReplies::remembered('tomates cerises pelées', $listCreated, $previousListSent);
                $replies["créé $listCreated $previousListSent"] = VoiceReplies::created('tomates cerises pelées', 'fruit & légume', $listCreated, $previousListSent);
            }
        }

        foreach ($replies as $label => $reply) {
            // Then : phrases de 15 mots au plus, trois phrases au plus, tutoiement
            $sentences = $this->sentences($reply);
            $this->assertLessThanOrEqual(3, count($sentences), "$label : « $reply »");
            foreach ($sentences as $sentence) {
                $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), "$label : « $sentence »");
            }
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(vous|votre|vos)(?![\p{L}])/u', mb_strtolower($reply), $label);
            $this->assertStringNotContainsString('!', $reply, $label);
            $this->assertStringNotContainsString('&', $reply, "$label : « & » se dit « et »");
            $this->assertStringNotContainsString('%', $reply, "$label : un modèle n'est pas rempli");
        }
    }

    public function testASentListIsOnlyMentionedWhenAListWasStartedAndTheMemoryPromiseGivesWayToIt(): void
    {
        $this->assertStringNotContainsString('drive', VoiceReplies::remembered('persil', false, true));
        $this->assertStringNotContainsString('drive', VoiceReplies::created('persil', 'fruit & légume', false, true));
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Je m'en souviendrai.", VoiceReplies::remembered('persil', true, false));
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Ta liste précédente est partie au drive.", VoiceReplies::remembered('persil', true, true));
        $this->assertSame("C'est créé et noté : persil, rayon fruit et légume.", VoiceReplies::created('persil', 'Fruit & légume', false, false));
        $this->assertSame("J'ai créé persil, rayon fruit et légume, et commencé une nouvelle liste.", VoiceReplies::created('persil', 'Fruit & légume', true, false));
        $this->assertSame("J'ai créé persil, rayon fruit et légume, et commencé une nouvelle liste. Ta liste précédente est partie au drive.", VoiceReplies::created('persil', 'Fruit & légume', true, true));
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

                    // Then : deux phrases au plus, jamais de relance (la réponse est une affirmation)
                    $this->assertLessThanOrEqual(2, count($this->sentences($answer)), $answer);
                    $this->assertStringNotContainsString('Autre chose', $answer);
                    foreach ($this->sentences($answer) as $sentence) {
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

    public function testTheSignAmpersandIsSpokenAsAnd(): void
    {
        $this->assertSame('fruit et légume', VoiceReplies::speakable('Fruit & légume'));
        $this->assertSame('fruit et légume', VoiceReplies::speakable('fruit&légume'));
    }

    public function testAPhraseTheUserSaidIsSpokenBackSafely(): void
    {
        $answer = VoiceReplies::unknown("<speak>XYZ</speak>\u{0007}" . str_repeat('z', 200), []);

        $this->assertLessThanOrEqual(60 + mb_strlen(VoiceReplies::ASK_CREATE), mb_strlen($answer));
        $this->assertStringNotContainsString("\u{0007}", $answer);
        $this->assertStringStartsWith('Je ne connais pas <speak>xyz</speak>', $answer, "texte brut : la balise n'est que du texte pour PlainText, jamais du SSML");
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // La quantité dite et le total de la ligne (ADR 0029 décision 5, amendée le 2026-10-25)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return AddedLine une première ligne, `$added` dans l'unité `$unit`
     */
    private static function line(float $added, string $unitSlug = 'unite', string $unitName = 'unité', bool $cited = false, bool $substituted = false): AddedLine
    {
        return new AddedLine($added, $added, $unitSlug, $unitName, existed: false, unitCited: $cited, unitSubstituted: $substituted);
    }

    /**
     * @return AddedLine une ligne qui existait déjà et vaut maintenant `$total`
     */
    private static function existing(float $added, float $total, string $unitSlug = 'unite', string $unitName = 'unité', bool $cited = false, bool $substituted = false): AddedLine
    {
        return new AddedLine($added, $total, $unitSlug, $unitName, existed: true, unitCited: $cited, unitSubstituted: $substituted);
    }

    #[DataProvider('items')]
    public function testWhatWasAddedIsSaidWithItsQuantityAndItsUnitOnlyWhenItHelps(string $expected, string $name, AddedLine $line): void
    {
        $this->assertSame($expected, VoiceReplies::item($name, $line));
    }

    /**
     * @return iterable<string, array{string, string, AddedLine}>
     */
    public static function items(): iterable
    {
        yield 'une unité, rien dit' => ['pain', 'pain', AddedLine::single()];
        yield 'plusieurs, rien dit' => ['3 croquettes', 'croquette', self::line(3)];
        yield 'plusieurs, nom déjà au pluriel' => ['3 croquettes', 'croquettes', self::line(3)];
        yield 'plusieurs, nom en plusieurs mots' => ['2 pommes de terre', 'pomme de terre', self::line(2)];
        yield 'plusieurs, nom en s' => ['2 pois', 'pois', self::line(2)];
        yield 'deux kilos' => ['2 kilos de tomate', 'tomate', self::line(2, 'kilo', 'kilo', cited: true)];
        yield 'un kilo' => ['1 kilo de tomate', 'tomate', self::line(1, 'kilo', 'kilo', cited: true)];
        yield 'un kilo et demi' => ['1,5 kilo de farine', 'farine', self::line(1.5, 'kilo', 'kilo', cited: true)];
        yield 'trois paquets' => ['3 paquets de croquettes', 'croquettes', self::line(3, 'paquet', 'paquet', cited: true)];
        yield 'une unité à plusieurs mots' => ['2 cuillères à soupe de sucre', 'sucre', self::line(2, 'cuillere-a-soupe', 'cuillère à soupe', cited: true)];
        yield 'voyelle' => ["2 kilos d'ail", 'ail', self::line(2, 'kilo', 'kilo', cited: true)];
        yield 'h muet' => ["2 kilos d'huile", 'huile', self::line(2, 'kilo', 'kilo', cited: true)];
        yield 'h aspiré' => ['2 kilos de haricots', 'haricots', self::line(2, 'kilo', 'kilo', cited: true)];
        yield 'l\'unité « unité » citée ne se dit pas' => ['2 pains', 'pain', self::line(2, 'unite', 'unité', cited: true)];
        yield 'l\'unité dite n\'a pas servi' => ['2 paquets de croquettes', 'croquettes', self::line(2, 'paquet', 'paquet', cited: true, substituted: true)];
        yield 'l\'unité dite n\'a pas servi, la ligne compte des unités' => ['2 croquettes', 'croquettes', self::line(2, 'unite', 'unité', cited: true, substituted: true)];
        yield 'une unité non citée ne se dit pas' => ['3 croquettes', 'croquettes', self::line(3, 'paquet', 'paquet')];
        yield 'un seul, unité non citée' => ['croquettes', 'croquettes', self::line(1, 'paquet', 'paquet')];
    }

    public function testAFirstAdditionKeepsTheExistingVariantsWithTheQuantity(): void
    {
        $three = self::line(3);
        $kilos = self::line(2, 'kilo', 'kilo', cited: true);

        $this->assertSame("C'est noté : 3 croquettes.", VoiceReplies::added('croquettes', 'animal', true, false, false, $three));
        $this->assertSame("C'est noté : 2 kilos de tomate.", VoiceReplies::added('tomate', 'fruit et légume', true, false, false, $kilos));
        $this->assertSame("J'ai mis 3 croquettes, animal.", VoiceReplies::added('croquettes', 'animal', false, false, false, $three));
        $this->assertSame("J'ai commencé une nouvelle liste avec 3 croquettes.", VoiceReplies::added('croquettes', 'animal', true, true, false, $three));
        $this->assertSame("J'ai commencé une nouvelle liste avec 2 kilos de tomate. Ta liste précédente est partie au drive.", VoiceReplies::added('tomate', 'fruit et légume', true, true, true, $kilos));
        $this->assertSame("C'est noté : 3 croquettes. Je m'en souviendrai.", VoiceReplies::remembered('croquettes', false, false, $three));
        $this->assertSame("C'est noté : 3 croquettes, animal. Je m'en souviendrai.", VoiceReplies::rememberedIn('croquettes', 'animal', false, false, $three));
        $this->assertSame("C'est créé et noté : 3 croquettes, rayon animal.", VoiceReplies::created('croquettes', 'animal', false, false, $three));
        $this->assertSame("J'ai créé 3 croquettes, rayon animal, et commencé une nouvelle liste.", VoiceReplies::created('croquettes', 'animal', true, false, $three));
        // Sans ligne passée, le texte d'avant la quantité
        $this->assertSame("C'est noté : pain.", VoiceReplies::added('pain', 'boulangerie', true, false, false));
    }

    #[DataProvider('totals')]
    public function testAnIngredientAlreadyInTheListSaysTheTotal(string $expected, AddedLine $line): void
    {
        $this->assertSame($expected, VoiceReplies::added('pain', 'boulangerie', true, false, false, $line));
    }

    /**
     * @return iterable<string, array{string, AddedLine}>
     */
    public static function totals(): iterable
    {
        yield 'deux unités' => ["C'est ajouté, tu en as maintenant 2 dans ta liste.", self::existing(1, 2)];
        yield 'cinq unités' => ["C'est ajouté, tu en as maintenant 5 dans ta liste.", self::existing(2, 5)];
        yield 'trois paquets' => ["C'est ajouté, tu en as maintenant 3 paquets dans ta liste.", self::existing(1, 3, 'paquet', 'paquet')];
        yield 'deux kilos' => ["C'est ajouté, tu en as maintenant 2 kilos dans ta liste.", self::existing(1, 2, 'kilo', 'kilo', cited: true)];
        yield 'un kilo et demi' => ["C'est ajouté, tu en as maintenant 1,5 kilo dans ta liste.", self::existing(0.5, 1.5, 'kilo', 'kilo')];
        yield 'deux cuillères à soupe' => ["C'est ajouté, tu en as maintenant 2 cuillères à soupe dans ta liste.", self::existing(1, 2, 'cuillere-a-soupe', 'cuillère à soupe')];
        yield 'un quart' => ["C'est ajouté, tu en as maintenant 0,25 kilo dans ta liste.", self::existing(0.25, 0.25, 'kilo', 'kilo')];
        yield 'poussière de virgule flottante' => ["C'est ajouté, tu en as maintenant 2001 grammes dans ta liste.", self::existing(2000.0000001, 2001.0000001, 'gramme', 'gramme')];
        yield 'l\'unité dite n\'est pas celle de la ligne' => ["C'est ajouté en paquets, tu en as maintenant 5 dans ta liste.", self::existing(2, 5, 'paquet', 'paquet', cited: true, substituted: true)];
        yield 'la ligne compte des unités' => ["C'est ajouté en unités, tu en as maintenant 5 dans ta liste.", self::existing(2, 5, 'unite', 'unité', cited: true, substituted: true)];
    }

    public function testTheTotalReplacesCestNoteOnlyWhenTheLineExistedAndKeepsThePromiseOfTheMemory(): void
    {
        $line = self::existing(1, 2);

        // La phrase du total remplace « C'est noté » seulement quand la ligne existait déjà
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste. Je m'en souviendrai.", VoiceReplies::remembered('pain', false, false, $line));
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste. Je m'en souviendrai.", VoiceReplies::rememberedIn('tomate', 'surgelé', false, false, $line));
        // Un choix deviné dit d'abord ce qui a été mis et où
        $this->assertSame("J'ai mis pain, boulangerie.", VoiceReplies::added('pain', 'boulangerie', false, false, false, $line));
        // Une liste neuve n'a pas de ligne existante : jamais de total
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", VoiceReplies::added('pain', 'boulangerie', true, true, false, $line));
    }

    public function testEveryAssembledReplyWithAQuantityStaysShortWithRealisticNames(): void
    {
        $lines = [
            'plusieurs' => self::line(12),
            'au kilo' => self::line(2, 'kilo', 'kilo', cited: true),
            'boîtes' => self::line(3, 'boite', 'boîte', cited: true),
            'écartée' => self::line(2, 'unite', 'unité', cited: true, substituted: true),
            'total' => self::existing(1, 12, 'cuillere-a-cafe', 'cuillère à café'),
            'total converti' => self::existing(2000, 2001, 'cuillere-a-soupe', 'cuillère à soupe', cited: true, substituted: true),
        ];

        foreach ($lines as $label => $line) {
            foreach ([true, false] as $isSure) {
                foreach ([true, false] as $listCreated) {
                    foreach ([true, false] as $previousListSent) {
                        $answers = [
                            VoiceReplies::added('farine de blé', 'épicerie', $isSure, $listCreated, $previousListSent, $line),
                            VoiceReplies::remembered('farine de blé', $listCreated, $previousListSent, $line),
                            VoiceReplies::rememberedIn('farine de blé', 'épicerie', $listCreated, $previousListSent, $line),
                        ];
                        // Un produit tout juste créé n'avait pas de ligne : jamais de total
                        if (!$line->existed) {
                            $answers[] = VoiceReplies::created('farine de blé', 'épicerie', $listCreated, $previousListSent, $line);
                        }
                        foreach ($answers as $answer) {
                            // Then : deux phrases au plus (trois pour la création d'une liste suivie du drive), 15 mots par phrase, jamais de relance
                            $this->assertLessThanOrEqual(3, count($this->sentences($answer)), "$label : $answer");
                            $this->assertStringNotContainsString('Autre chose', $answer);
                            $this->assertStringNotContainsString('%', $answer);
                            foreach ($this->sentences($answer) as $sentence) {
                                $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), "$label : « $sentence »");
                            }
                        }
                    }
                }
            }
        }
    }

    public function testTheTotalAnswerIsTwoShortSentencesAtMost(): void
    {
        foreach ([self::existing(1, 2), self::existing(2, 12, 'cuillere-a-soupe', 'cuillère à soupe', cited: true, substituted: true)] as $line) {
            $answer = VoiceReplies::added('pain', 'boulangerie', true, false, false, $line);

            $this->assertLessThanOrEqual(2, count($this->sentences($answer)), $answer);
            foreach ($this->sentences($answer) as $sentence) {
                $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), $sentence);
            }
        }
    }

    public function testAnUnusableQuantityIsAskedAgainPolitely(): void
    {
        $this->assertSame(['Combien ?', 'Redis ta demande avec un nombre de 1 à 99.'], $this->sentences($this->said('HOW_MANY')));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer et consulter (ADR 0029 décision 12, VOX-304)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testTheAnswersToARemovalAreTheOnesTheOwnerGives(): void
    {
        // La ligne disparaît, ou diminue, avec ou sans unité
        $this->assertSame("C'est retiré : croquette. Il n'y en a plus dans ta liste.", VoiceReplies::removed('croquette', new RemovedLine(1.0, 0.0, 'unite', 'unité')));
        $this->assertSame("C'est retiré, il t'en reste 2 paquets dans ta liste.", VoiceReplies::removed('croquette', new RemovedLine(1.0, 2.0, 'paquet', 'paquet')));
        $this->assertSame("C'est retiré, il t'en reste 3 dans ta liste.", VoiceReplies::removed('pain', new RemovedLine(1.0, 3.0, 'unite', 'unité')));
        $this->assertSame("C'est retiré, il t'en reste 1,5 litre dans ta liste.", VoiceReplies::removed('lait', new RemovedLine(0.5, 1.5, 'litre', 'litre')));
        // Ce qui disparaît est dit avec sa quantité et son unité
        $this->assertSame("C'est retiré : 2 croquettes. Il n'y en a plus dans ta liste.", VoiceReplies::removed('croquette', new RemovedLine(2.0, 0.0, 'unite', 'unité')));
        $this->assertSame("C'est retiré : 2 kilos de tomate. Il n'y en a plus dans ta liste.", VoiceReplies::removed('tomate', new RemovedLine(2.0, 0.0, 'kilo', 'kilo', unitCited: true)));
        // L'unité dite n'est pas celle de la ligne : on nomme celle de la ligne
        $this->assertSame("C'est retiré en grammes, il t'en reste 1500 grammes dans ta liste.", VoiceReplies::removed('pâtes', new RemovedLine(1000.0, 1500.0, 'gramme', 'gramme', unitCited: true, unitSubstituted: true)));
        // Pas dans la liste, avec « de » ou « d' »
        $this->assertSame("Il n'y a pas de croquette dans ta liste.", VoiceReplies::notInList('croquette'));
        $this->assertSame("Il n'y a pas d'ail dans ta liste.", VoiceReplies::notInList('ail'));
        $this->assertSame("Tu n'as pas de liste en cours.", $this->said('NO_LIST'));
        $this->assertSame("D'accord, je ne retire rien.", $this->said('DECLINED_REMOVAL'));
        $this->assertSame('Dis par exemple : enlève du pain.', $this->said('REMOVE_EXAMPLE'));
    }

    public function testTheAnswersToAQuestionAboutTheListAreTheOnesTheOwnerGives(): void
    {
        $this->assertSame('Oui, tu en as 2 paquets.', VoiceReplies::inList(new ListLine('croquette', 'animal', 2.0, 'paquet', 'paquet')));
        $this->assertSame('Oui, tu en as 3.', VoiceReplies::inList(new ListLine('pain', 'boulangerie', 3.0, 'unite', 'unité')));
        $this->assertSame('Oui, tu en as 1 litre.', VoiceReplies::inList(new ListLine('lait', 'crèmerie', 1.0, 'litre', 'litre')));
        $this->assertSame("Non, il n'y a pas de croquette dans ta liste.", VoiceReplies::notInListAnswer('croquette'));
        $this->assertSame("Non, il n'y a pas d'ail dans ta liste.", VoiceReplies::notInListAnswer('ail'));
        $this->assertSame("Dis par exemple : est-ce qu'il y a du pain dans ma liste ?", $this->said('CHECK_EXAMPLE'));
    }

    public function testSeveralLinesAreEnumeratedOnePerDepartmentInTwoShortSentencesAtMost(): void
    {
        // Given : « tomate » en fruit et légume, en surgelé et en conserve
        $answer = VoiceReplies::inListMany([
            new ListLine('tomate', 'fruit & légume', 2.0, 'unite', 'unité'),
            new ListLine('tomate', 'surgelé', 1.0, 'unite', 'unité'),
            new ListLine('tomate', 'conserve', 3.0, 'unite', 'unité'),
        ]);

        // Then : la première dit le produit, les suivantes seulement leur rayon
        $this->assertSame('Oui : 2 tomates en fruit et légume, 1 en surgelé, 3 en conserve.', $answer);
        $this->assertLessThanOrEqual(2, count($this->sentences($answer)));
        $this->assertStringNotContainsString('&', $answer);
    }

    public function testALineWithAUnitIsEnumeratedWithItsUnit(): void
    {
        $this->assertSame(
            'Oui : 2 litres de lait en crèmerie, 1 litre en bébé.',
            VoiceReplies::inListMany([new ListLine('lait', 'crèmerie', 2.0, 'litre', 'litre'), new ListLine('lait', 'bébé', 1.0, 'litre', 'litre')]),
        );
        $this->assertSame(
            "Oui : 2 kilos d'ail en épicerie, 3 en fruit et légume.",
            VoiceReplies::inListMany([new ListLine('ail', 'épicerie', 2.0, 'kilo', 'kilo'), new ListLine('ail', 'fruit et légume', 3.0, 'unite', 'unité')]),
        );
    }

    public function testTheNewAnswersObeyTheVoiceOfTheProduct(): void
    {
        // Given : tout ce que retirer et consulter peuvent dire, avec un nom long et des quantités variées
        $answers = [
            VoiceReplies::removed('croquette pour chat adulte stérilisé', new RemovedLine(2.0, 0.0, 'paquet', 'paquet', unitCited: true)),
            VoiceReplies::removed('croquette', new RemovedLine(1.0, 12.0, 'paquet', 'paquet', unitSubstituted: true)),
            VoiceReplies::notInList('croquette pour chat adulte stérilisé'),
            VoiceReplies::notInListAnswer('croquette pour chat adulte stérilisé'),
            VoiceReplies::inList(new ListLine('croquette', 'animal', 12.5, 'paquet', 'paquet')),
            VoiceReplies::inListMany([new ListLine('tomate', 'fruit & légume', 2.0, 'unite', 'unité'), new ListLine('tomate', 'surgelé', 1.0, 'unite', 'unité')]),
        ];

        foreach ($answers as $answer) {
            // Then : une à deux phrases, 15 mots au plus chacune, tutoiement, jamais de relance ni de jargon
            $this->assertLessThanOrEqual(2, count($this->sentences($answer)), $answer);
            foreach ($this->sentences($answer) as $sentence) {
                $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $sentence)), $sentence);
            }
            $this->assertStringNotContainsStringIgnoringCase('autre chose', $answer);
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(vous|votre|vos)(?![\p{L}])/u', mb_strtolower($answer), $answer);
        }
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
        $this->assertGreaterThanOrEqual(25, count($catalogue), 'le catalogue n\'est plus lu');

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
