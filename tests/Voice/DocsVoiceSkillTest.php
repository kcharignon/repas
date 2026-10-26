<?php

namespace Repas\Tests\Voice;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Service\AlexaDialogue;
use Repas\Voice\Domain\Service\SpokenUnits;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Le paquet du skill (`docs/voice/skill/`, VOX-205) est d'accord avec le webhook (VOX-204) : le modèle d'interaction est du JSON valide, chaque
 * intention qu'il déclare est gérée par le webhook (et chaque intention gérée est déclarée), le type de valeurs `INGREDIENT` n'a que des noms
 * uniques, sans chiffre ni parenthèse, et le manifeste désigne la route du webhook sans demander aucun accès à la liste d'Amazon.
 *
 * Ce que ce test ne peut PAS vérifier (la console d'Amazon n'est pas appelée, aucun agent ne la contacte) : que la console accepte le JSON et le
 * nombre de valeurs qu'elle permet par type. Ce sont les critères de VOX-002.
 */
class DocsVoiceSkillTest extends KernelTestCase
{
    private const string MODEL_PATH = '/docs/voice/skill/interaction-model.fr-FR.json';
    private const string MANIFEST_PATH = '/docs/voice/skill/skill.json';
    private const string CATALOGUE_PATH = '/docs/ingredients/base-ingredients.tsv';

    /** Amazon : une valeur de type personnalisé a 140 caractères au plus. */
    private const int MAX_VALUE_LENGTH = 140;

    public function testTheInteractionModelIsValidJsonWithAnInvocationName(): void
    {
        // When
        $language = $this->languageModel();

        // Then : en minuscules, sans le mot d'appel d'Alexa (un seul mot suffit en développement : « jarvis », décision D1)
        $invocation = $language['invocationName'];
        $this->assertIsString($invocation);
        $this->assertMatchesRegularExpression("/^[\\p{Ll}' ]+$/u", $invocation, 'minuscules et espaces seulement');
        $this->assertDoesNotMatchRegularExpression('/\b(alexa|amazon|echo|computer)\b/i', $invocation);
    }

    public function testEveryIntentOfTheModelIsHandledByTheWebhookAndEveryHandledIntentIsInTheModel(): void
    {
        // Given
        $inModel = array_map(static fn(array $intent): string => $intent['name'], $this->languageModel()['intents']);
        $handled = array_map(static fn(AlexaIntent $intent): string => $intent->value, AlexaIntent::cases());

        // Then : les mêmes noms, des deux côtés
        sort($inModel);
        sort($handled);
        $this->assertSame($handled, $inModel, 'le modèle d\'interaction et AlexaIntent doivent déclarer les mêmes intentions');
        $this->assertSame(array_values(array_unique($inModel)), $inModel, 'aucune intention déclarée deux fois');
    }

    #[DataProvider('intentsOfTheModel')]
    public function testTheWebhookDialogueAnswersEachIntentOfTheModel(string $intent): void
    {
        // Given
        $dialogue = new AlexaDialogue();
        $request = match ($intent) {
            'AddItemIntent' => AlexaTestRequest::addItem('du pain'),
            'RemoveItemIntent' => AlexaTestRequest::removeItem('du pain'),
            'CheckItemIntent' => AlexaTestRequest::checkItem('du pain'),
            'AddMealIntent' => AlexaTestRequest::addMeal('hachis parmentier'),
            default => AlexaTestRequest::intent($intent),
        };

        // When
        $answer = $dialogue->answer(AlexaRequest::fromDecoded($request->toArray()));

        // Then : une réponse parlée, ou (un article compris) le cas d'usage qui l'ajoute, le retire ou le cherche dans la liste
        if (in_array($intent, ['AddItemIntent', 'RemoveItemIntent', 'CheckItemIntent', 'AddMealIntent'], true)) {
            $this->assertNull($answer, "$intent avec un article est confié à son cas d'usage");
        } else {
            $this->assertInstanceOf(AlexaResponse::class, $answer, $intent);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function intentsOfTheModel(): iterable
    {
        foreach (self::model()['interactionModel']['languageModel']['intents'] as $intent) {
            yield $intent['name'] => [$intent['name']];
        }
    }

    public function testTheQuantityTypeHoldsOneToNinetyNineWithTheNumberAsIdentifierAndTheWordsAsValues(): void
    {
        // Given
        $type = array_values(array_filter($this->languageModel()['types'], static fn(array $type): bool => $type['name'] === 'QUANTITY'))[0];

        // Then : le webhook lit l'identifiant comme un nombre (AlexaRequest::quantity) ; Amazon refuse les chiffres dans une valeur : « deux » rend « 2 »
        $this->assertCount(99, $type['values']);
        $words = [];
        foreach ($type['values'] as $index => $value) {
            $this->assertSame((string) ($index + 1), $value['id']);
            foreach ([$value['name']['value'], ...$value['name']['synonyms']] as $word) {
                $this->assertMatchesRegularExpression('/^\p{Ll}+(?: \p{Ll}+)*$/u', $word, 'des lettres et des espaces, sans chiffre ni trait d\'union');
                $words[] = $word;
            }
        }
        $this->assertSame(array_values(array_unique($words)), $words, 'aucun mot en double entre deux nombres');
        $this->assertContains('vingt et un', $words);
        $this->assertContains('quatre vingt dix neuf', $words);
    }

    public function testTheAddItemIntentHasItsItemQuantityAndUnitSlotsAndWellFormedSamples(): void
    {
        // Given
        $intent = $this->intent('AddItemIntent');

        // Then : les créneaux que le webhook lit (`request.intent.slots.item`, `quantity`, `unit`), tous de nos types (le `AMAZON.NUMBER` d'Amazon
        // laissait la quantité vide devant une unité : essai réel du 2026-10-25)
        $this->assertSame([
            ['name' => 'item', 'type' => 'INGREDIENT'],
            ['name' => 'quantity', 'type' => 'QUANTITY'],
            ['name' => 'unit', 'type' => 'UNIT'],
        ], $intent['slots']);
        $this->assertContains('INGREDIENT', array_map(static fn(array $type): string => $type['name'], $this->languageModel()['types']));

        $samples = $intent['samples'];
        $this->assertGreaterThanOrEqual(5, count($samples));
        $this->assertSame(array_values(array_unique($samples)), $samples, 'aucune phrase en double');
        foreach ($samples as $sample) {
            $this->assertIsString($sample);
            $this->assertSame(1, substr_count($sample, '{item}'), $sample);
            // Le nom du produit, et au plus une fois la quantité et l'unité : aucun autre créneau
            $this->assertSame(0, substr_count(str_replace(['{item}', '{quantity}', '{unit}'], '', $sample), '{'), "« $sample » : {item}, {quantity} et {unit} seulement");
            $this->assertLessThanOrEqual(1, substr_count($sample, '{quantity}'), $sample);
            $this->assertLessThanOrEqual(1, substr_count($sample, '{unit}'), $sample);
            $this->assertFalse(str_contains($sample, '{unit}') && !str_contains($sample, '{quantity}'), "« $sample » : une unité se dit avec sa quantité");
            $this->assertSame($sample, trim($sample));
            $this->assertDoesNotMatchRegularExpression('/\d/', $sample, "« $sample » : pas de chiffre dans une phrase d'exemple");
            $this->assertSame(mb_strtolower($sample), $sample, "« $sample » : en minuscules");
        }
    }

    public function testTheAddMealIntentHasItsRecipeAndServingsSlotsAndWellFormedSamples(): void
    {
        // Given
        $intent = $this->intent('AddMealIntent');

        // Then : le nom de la recette est du TEXTE LIBRE (`AMAZON.SearchQuery`) : avec un type à nous, « planifie une fondue » partait vers les intentions des produits ou des réponses
        // (profileur d'énoncés, 2026-10-26). Amazon interdit de combiner ce type avec un autre créneau dans un exemple : le nombre de personnes (« pour six personnes »)
        // reste au bout du texte, que le webhook lit (`SpokenMealPhrase::parse`)
        $this->assertSame([['name' => 'recipe', 'type' => 'AMAZON.SearchQuery']], $intent['slots']);

        $samples = $intent['samples'];
        $this->assertGreaterThanOrEqual(80, count($samples));
        $this->assertSame(array_values(array_unique($samples)), $samples, 'aucune phrase en double');
        $verbs = [];
        $markers = [];
        foreach ($samples as $sample) {
            $this->assertSame(1, substr_count($sample, '{recipe}'), $sample);
            $this->assertSame(0, substr_count(str_replace(['{recipe}', '{servings}'], '', $sample), '{'), "« $sample » : {recipe} et {servings} seulement");
            $this->assertLessThanOrEqual(1, substr_count($sample, '{servings}'), $sample);
            $this->assertStringNotContainsString("'{", $sample, "« $sample » : une espace après l'apostrophe, avant un créneau");
            // « un » et « une » sont des articles seulement juste après un verbe de planification (« planifie une fondue ») : le seul nombre de la phrase vient après « pour »
            $withoutArticle = preg_replace('/^(planifie|planifier|prévois|prévoir|prépare|préparer|mets au menu|mettre au menu) (un|une) /u', '$1 ', $sample);
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(un|une)(?![\p{L}])/u', $withoutArticle, "« $sample » : « un » et « une » sont des nombres, un article seulement après un verbe de planification");
            $this->assertDoesNotMatchRegularExpression('/\d|-/', $sample, "« $sample » : ni chiffre ni trait d'union");
            $this->assertSame(trim($sample), $sample);
            $this->assertSame(mb_strtolower($sample), $sample, "« $sample » : en minuscules");
            // Une quantité de personnes se dit en fin de phrase, après « pour »
            if (str_contains($sample, '{servings}')) {
                $this->assertMatchesRegularExpression('/ pour \{servings\}( personnes)?$/u', $sample);
            }
            $verbs[preg_replace('/ (le repas|la recette)( de)?.*$| \{recipe\}.*$/u', '', $sample)] = true;
            if (preg_match('/(le repas|la recette)/u', $sample, $match) === 1) {
                $markers[$match[1]] = true;
            }
        }
        // Les verbes de la décision du propriétaire (2026-10-26) : planifie, prévois, prépare, mets au menu, ajoute le repas / la recette
        foreach (['planifie', 'prévois', 'prépare', 'mets au menu', 'ajoute', 'planifier', 'prévoir', 'préparer', 'mettre au menu', 'ajouter'] as $verb) {
            $this->assertArrayHasKey($verb, $verbs, "le verbe « $verb » manque");
        }
        $this->assertSame(['le repas' => true, 'la recette' => true], $markers);
        $this->assertContains('planifie {recipe}', $samples);
        $this->assertContains('planifie le repas {recipe}', $samples);
        // Le nombre de personnes ne se dit pas dans un exemple : `AMAZON.SearchQuery` ne se combine avec aucun autre créneau (il reste au bout du texte)
        foreach ($samples as $sample) {
            $this->assertStringNotContainsString('{servings}', $sample);
            $this->assertNotSame('{recipe}', $sample, 'une phrase d\'exemple avec un texte libre porte toujours un mot d\'accroche');
        }
        // Le mot seul, sans verbe (« la recette fondue ») : sinon Amazon la rangeait dans RemoveMealIntent
        $this->assertContains('la recette {recipe}', $samples);
        $this->assertContains('le repas {recipe}', $samples);
        // L'article se dit après un verbe de planification (essai réel du 2026-10-26 : « planifier une fondue » n'était pas compris)
        foreach (['planifie une {recipe}', 'planifier un {recipe}', 'prévois des {recipe}', 'mets au menu la {recipe}', 'préparer le {recipe}'] as $withArticle) {
            $this->assertContains($withArticle, $samples);
        }
        // … mais jamais après « ajoute » (« ajoute un hachis » reste un produit)
        foreach (['ajoute un {recipe}', 'ajoute une {recipe}', 'ajouter une {recipe}', 'ajoute le {recipe}'] as $notAMeal) {
            $this->assertNotContains($notAMeal, $samples);
        }
        $this->assertContains('ajoute la recette {recipe}', $samples);
        $this->assertContains('ajoute le repas {recipe}', $samples);
        // « ajoute » seul reste un produit : jamais d'échantillon d'ajout de repas sans le mot
        foreach (['ajoute {recipe}', 'mets {recipe}', 'note {recipe}', 'ajouter {recipe}'] as $bare) {
            $this->assertNotContains($bare, $samples);
        }
    }

    public function testTheRecipeIsFreeTextAndTheKindTypeHasTheTwoAnswers(): void
    {
        // Given
        $kinds = [];
        foreach ($this->type('KIND')['values'] as $value) {
            $kinds[$value['id']] = [$value['name']['value'], ...$value['name']['synonyms']];
        }

        // Then : plus de type des recettes (texte libre), et « une recette » / « un produit » comme le webhook les lit
        $this->assertArrayNotHasKey('RECIPE', array_column($this->languageModel()['types'], null, 'name'));
        $this->assertSame(['meal', 'item'], array_keys($kinds));
        $this->assertContains('recette', $kinds['meal']);
        $this->assertContains('repas', $kinds['meal']);
        $this->assertContains('produit', $kinds['item']);
        $this->assertContains('article', $kinds['item']);
    }

    public function testEveryWordOfTheKindTypeIsUnderstoodByTheWebhookAsTheSameKind(): void
    {
        // Given
        $meal = new \Repas\Voice\Domain\Service\SpokenMealPhrase();

        // Then : le texte seul (sans la résolution d'entité) dit la même chose que l'identifiant du modèle
        foreach ($this->type('KIND')['values'] as $value) {
            foreach ([$value['name']['value'], ...$value['name']['synonyms']] as $word) {
                $this->assertSame($value['id'], $meal->kindOf($word), $word);
            }
        }
    }

    public function testTheKeepMealIntentIsUnderstoodByTheWebhookAsNothingToChange(): void
    {
        // Given
        $meal = new \Repas\Voice\Domain\Service\SpokenMealPhrase();

        // Then : chaque phrase du modèle est comprise comme « rien » même quand Amazon choisit une autre intention
        foreach ($this->intent('KeepMealIntent')['samples'] as $sample) {
            $this->assertTrue($meal->isKeepAnswer($sample), $sample);
        }
    }


    public function testTheRemoveItemIntentHasTheSameSlotsAsTheAddAndWellFormedSamples(): void
    {
        // Given
        $intent = $this->intent('RemoveItemIntent');

        // Then : les mêmes créneaux que l'ajout (ADR 0029 décision 12), tous de nos types
        $this->assertSame($this->intent('AddItemIntent')['slots'], $intent['slots']);

        $samples = $intent['samples'];
        $this->assertGreaterThanOrEqual(50, count($samples));
        $this->assertSame(array_values(array_unique($samples)), $samples, 'aucune phrase en double');
        $verbs = [];
        foreach ($samples as $sample) {
            $this->assertSame(1, substr_count($sample, '{item}'), $sample);
            $this->assertSame(0, substr_count(str_replace(['{item}', '{quantity}', '{unit}'], '', $sample), '{'), "« $sample » : {item}, {quantity} et {unit} seulement");
            $this->assertLessThanOrEqual(1, substr_count($sample, '{quantity}'), $sample);
            $this->assertLessThanOrEqual(1, substr_count($sample, '{unit}'), $sample);
            $this->assertFalse(str_contains($sample, '{unit}') && !str_contains($sample, '{quantity}'), "« $sample » : une unité se dit avec sa quantité");
            $this->assertStringNotContainsString("'{", $sample, "« $sample » : une espace après l'apostrophe, avant un créneau");
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(un|une)(?![\p{L}])/u', $sample, "« $sample » : « un » et « une » sont des nombres, jamais un mot de liaison");
            $this->assertDoesNotMatchRegularExpression('/\d|-/', $sample, "« $sample » : ni chiffre ni trait d'union");
            $this->assertSame(trim($sample), $sample);
            $this->assertSame(mb_strtolower($sample), $sample, "« $sample » : en minuscules");
            $verbs[explode(' ', $sample)[0]] = true;
        }
        // Les verbes de la demande du propriétaire : enlève, retire, déduis, supprime, ôte
        foreach (['enlève', 'retire', 'déduis', 'supprime', 'ôte'] as $verb) {
            $this->assertArrayHasKey($verb, $verbs);
        }
        // Aucun verbe d'ajout dans les phrases de retrait
        foreach (['ajoute', 'ajouter', 'mets', 'mettre', 'rajoute', 'rajouter', 'note'] as $verb) {
            $this->assertArrayNotHasKey($verb, $verbs);
        }
    }

    public function testTheCheckItemIntentHasOnlyTheItemSlotAndWellFormedSamples(): void
    {
        // Given
        $intent = $this->intent('CheckItemIntent');

        // Then : un seul créneau, le produit ; la réponse dit combien il y en a (ADR 0029 décision 12)
        $this->assertSame([['name' => 'item', 'type' => 'INGREDIENT']], $intent['slots']);

        $samples = $intent['samples'];
        $this->assertGreaterThanOrEqual(50, count($samples));
        $this->assertSame(array_values(array_unique($samples)), $samples, 'aucune phrase en double');
        foreach ($samples as $sample) {
            $this->assertSame(1, substr_count($sample, '{item}'), $sample);
            $this->assertSame(0, substr_count(str_replace('{item}', '', $sample), '{'), "« $sample » : {item} seulement");
            $this->assertStringNotContainsString("'{", $sample, "« $sample » : une espace après l'apostrophe, avant un créneau");
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(un|une)(?![\p{L}])/u', $sample, "« $sample » : « un » et « une » sont des nombres, jamais un mot de liaison");
            $this->assertDoesNotMatchRegularExpression('/\d|-/', $sample, "« $sample » : ni chiffre ni trait d'union");
            $this->assertSame(trim($sample), $sample);
            $this->assertSame(mb_strtolower($sample), $sample, "« $sample » : en minuscules");
        }
        $this->assertContains("est ce qu'il y a des {item} dans la liste", $samples);
        $this->assertContains("combien de {item} dans la liste", $samples);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Le dialogue d'un produit inconnu (ADR 0029 décision 11, VOX-303) : intentions, créneaux, types de valeurs
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{string, ?string, ?string}> l'intention, son créneau, le type du créneau
     */
    public static function dialogueIntents(): iterable
    {
        yield 'ChooseOptionIntent' => ['ChooseOptionIntent', 'rank', 'OPTION_RANK'];
        yield 'CreateItemIntent' => ['CreateItemIntent', null, null];
        yield 'AnswerDepartmentIntent' => ['AnswerDepartmentIntent', 'department', 'DEPARTMENT'];
        yield 'AnswerUnitIntent' => ['AnswerUnitIntent', 'unit', 'UNIT'];
        // Le repas (ADR 0029 décision 13, VOX-305) : aiguiller un produit ou une recette non compris, nouveau total de personnes, ne rien changer
        yield 'ChooseKindIntent' => ['ChooseKindIntent', 'kind', 'KIND'];
        yield 'AnswerServingsIntent' => ['AnswerServingsIntent', 'servings', 'QUANTITY'];
        yield 'KeepMealIntent' => ['KeepMealIntent', null, null];
    }

    #[DataProvider('dialogueIntents')]
    public function testEachIntentOfTheDialogueHasItsSlotAndSixToTenWellFormedSamplesWithTheBareFormOfItsSlot(string $name, ?string $slot, ?string $type): void
    {
        // Given
        $intent = $this->intent($name);

        // Then : le créneau que le webhook lit (`request.intent.slots.<nom>`), du bon type
        $this->assertSame($slot === null ? null : [['name' => $slot, 'type' => $type]], $intent['slots'] ?? null, $name);
        $samples = $intent['samples'];
        $this->assertSame(array_values(array_unique($samples)), $samples, "$name : aucune phrase en double");
        $this->assertGreaterThanOrEqual(6, count($samples), $name);
        $this->assertLessThanOrEqual(10, count($samples), $name);
        foreach ($samples as $sample) {
            $this->assertSame($sample, trim($sample), $name);
            $this->assertSame(mb_strtolower($sample), $sample, "$name : « $sample » en minuscules");
            $this->assertDoesNotMatchRegularExpression('/\d/', $sample, "$name : « $sample » : pas de chiffre dans une phrase d\'exemple");
            $withoutSlot = $slot === null ? $sample : str_replace('{' . $slot . '}', '', $sample);
            $this->assertSame($slot === null ? 0 : 1, substr_count($sample, $slot === null ? '{' : '{' . $slot . '}'), "$name : « $sample » : exactement un créneau, le sien");
            $this->assertSame(0, substr_count($withoutSlot, '{'), "$name : « $sample » : un seul créneau");
        }
        if ($slot !== null) {
            $this->assertContains('{' . $slot . '}', $samples, "$name : la forme réduite au créneau, pour la réponse brève");
        }
    }

    #[DataProvider('quantifiedForms')]
    public function testTheAddItemIntentSaysTheQuantityAndTheUnit(string $sample): void
    {
        // Then : les formes demandées par le propriétaire (2026-10-25), avec l'espace d'Amazon après l'apostrophe de « d' »
        $this->assertContains($sample, $this->intent('AddItemIntent')['samples']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quantifiedForms(): iterable
    {
        foreach (['ajoute', 'ajouter', 'mets', 'mettre', 'rajoute', 'rajouter', 'note'] as $verb) {
            foreach (['{quantity} {item}', '{quantity} de {item}', '{quantity} {unit} de {item}', "{quantity} {unit} d' {item}"] as $form) {
                yield "$verb $form" => ["$verb $form"];
            }
        }
        yield '{quantity} {item}' => ['{quantity} {item}'];
        yield '{quantity} {unit} de {item}' => ['{quantity} {unit} de {item}'];
        yield "{quantity} {unit} d' {item}" => ["{quantity} {unit} d' {item}"];
        // Les formes d'avant la quantité restent valables
        yield 'ajoute {item}' => ['ajoute {item}'];
        yield 'ajoute du {item}' => ['ajoute du {item}'];
        yield '{item}' => ['{item}'];
    }

    public function testEverySlotOfTheModelIsReadByTheWebhook(): void
    {
        // Given : chaque créneau déclaré par une intention du modèle, dit avec une valeur
        $readBy = [
            'item' => static fn(AlexaRequest $request): mixed => $request->phrase,
            'rank' => static fn(AlexaRequest $request): mixed => $request->rank?->text,
            'department' => static fn(AlexaRequest $request): mixed => $request->department?->text,
            // Un « mot d'unité » que l'unité ne connaît pas est le produit quand `item` est vide (« vingt et un yaourts ») : le créneau est lu dans les deux cas
            'unit' => static fn(AlexaRequest $request): mixed => $request->unit->text ?? $request->phrase,
            'quantity' => static fn(AlexaRequest $request): mixed => $request->quantity,
            'recipe' => static fn(AlexaRequest $request): mixed => $request->recipePhrase,
            'servings' => static fn(AlexaRequest $request): mixed => $request->servings,
            'kind' => static fn(AlexaRequest $request): mixed => $request->kind?->text,
        ];

        foreach ($this->languageModel()['intents'] as $intent) {
            foreach ($intent['slots'] ?? [] as $slot) {
                $this->assertArrayHasKey($slot['name'], $readBy, "{$intent['name']} : AlexaRequest ne lit pas le créneau {$slot['name']}");

                // When
                $body = AlexaTestRequest::intent($intent['name'])->withSlot($slot['name'], '2')->toArray();
                $request = AlexaRequest::fromDecoded($body);

                // Then
                $this->assertNotNull($readBy[$slot['name']]($request), "{$intent['name']} : le créneau {$slot['name']} n'est pas lu");
            }
        }
    }

    public function testEveryWayOfSayingAUnitInTheModelIsKnownToTheWebhookAndEveryUnitOfTheCatalogueIsSpoken(): void
    {
        // Given
        $units = new SpokenUnits();
        $values = $this->type('UNIT')['values'];

        // Then : chaque mot du type UNIT du modèle désigne la même unité pour le webhook (retrait de l'unité laissée en tête de `item`)
        foreach ($values as $value) {
            $words = [$value['name']['value'], ...($value['name']['synonyms'] ?? [])];
            foreach ($words as $word) {
                $this->assertSame($value['id'], $units->slugOf($word), "« $word » : l'unité {$value['id']} n'est pas connue de SpokenUnits sous ce nom");
                $this->assertSame(['unitSlug' => $value['id'], 'rest' => 'croquettes'], $units->splitLeading("$word de croquettes"), $word);
            }
        }
        // Then : et tout slug du catalogue est dit
        $expected = $this->fixtureSlugs('unit.json');
        $known = array_keys(SpokenUnits::FORMS);
        sort($expected);
        sort($known);
        $this->assertSame($expected, $known);
    }

    public function testNoSampleUtteranceIsSharedBetweenTwoIntents(): void
    {
        // Given : Amazon refuse (ou tranche au hasard) une phrase d'exemple écrite pour deux intentions
        $owners = [];
        foreach ($this->languageModel()['intents'] as $intent) {
            foreach ($intent['samples'] as $sample) {
                $owners[mb_strtolower($sample)][] = $intent['name'];
            }
        }

        // Then
        foreach ($owners as $sample => $intents) {
            $this->assertCount(1, $intents, "« $sample » est une phrase d'exemple de " . implode(' et de ', $intents));
        }
    }

    public function testTheListChoicesIntentHasManySpokenFormsWithoutSlotDigitsOrPunctuation(): void
    {
        // Given
        $intent = $this->intent('ListChoicesIntent');
        $samples = $intent['samples'];

        // Then : pas de créneau ; de 40 à 60 phrases, uniques, en minuscules, lettres et espaces, avec l'apostrophe seulement
        $this->assertArrayNotHasKey('slots', $intent);
        $this->assertGreaterThanOrEqual(40, count($samples));
        $this->assertLessThanOrEqual(60, count($samples));
        $this->assertSame(array_values(array_unique($samples)), $samples);
        foreach ($samples as $sample) {
            $this->assertMatchesRegularExpression("/^[\\p{Ll}' ]+\$/u", $sample, "« $sample » : lettres, espaces et apostrophe seulement");
            $this->assertStringNotContainsString('  ', $sample);
            $this->assertDoesNotMatchRegularExpression("/(?:^|\\s)(?:qu|d|l)'[\\p{Ll}]/u", $sample, "« $sample » : Amazon veut une espace après l'apostrophe de « qu' », « d' », « l' »");
        }
    }

    #[DataProvider('listChoicesForms')]
    public function testTheOwnerCanAskWhichOnesInManyWays(string $sample): void
    {
        $this->assertContains($sample, $this->intent('ListChoicesIntent')['samples']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listChoicesForms(): iterable
    {
        foreach ([
            'lequel', 'laquelle', 'lesquels', 'lesquelles', 'la liste', 'liste moi les rayons', 'liste moi les unités', 'rappelle moi les rayons',
            'rappelle moi les rayons qui existent', 'rappelle moi les unités', 'quels rayons existent', 'quelles unités existent', 'quels sont les rayons',
            'quels sont les rayons possibles', 'dis moi les rayons', 'dis moi les unités', 'donne moi la liste des rayons', 'donne moi la liste des unités',
            "c'est quoi les rayons", "c'est quoi les unités", "qu' est ce qu' il y a comme rayons", "qu' est ce qu' il y a comme unités",
            'je ne connais pas les rayons', 'je ne sais pas', 'je ne me souviens plus', 'énumère les rayons', 'énumère les unités', 'répète les choix',
            'redis moi les choix', 'les choix possibles', 'quelles sont les options',
        ] as $sample) {
            yield $sample => [$sample];
        }
    }

    public function testTheNewBuiltInIntentsAreDeclared(): void
    {
        $names = array_map(static fn(array $intent): string => $intent['name'], $this->languageModel()['intents']);

        $this->assertContains('AMAZON.YesIntent', $names);
        $this->assertContains('AMAZON.NoIntent', $names);
    }

    public function testTheDepartmentValuesAreTheDepartmentsOfTheFixturesByTheirSlug(): void
    {
        // Given
        $ids = array_map(static fn(array $value): string => $value['id'], $this->type('DEPARTMENT')['values']);

        // Then : mêmes identifiants que le catalogue (`resolutionsPerAuthority[].values[].value.id` = le slug du rayon)
        $expected = $this->fixtureSlugs('department.json');
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testTheUnitValuesAreTheUnitsOfTheFixturesByTheirSlug(): void
    {
        // Given
        $ids = array_map(static fn(array $value): string => $value['id'], $this->type('UNIT')['values']);

        // Then
        $expected = $this->fixtureSlugs('unit.json');
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testTheRankValuesAreTheRanksOneToThree(): void
    {
        // Then : l'identifiant est le rang (les identifiants peuvent être des chiffres, pas les valeurs)
        $this->assertSame(['1', '2', '3'], array_map(static fn(array $value): string => $value['id'], $this->type('OPTION_RANK')['values']));
    }

    public function testUnitsAreSaidInTheWordsOfEverydayLife(): void
    {
        // Given : les synonymes de l'unité « unité » et du kilo
        $byId = [];
        foreach ($this->type('UNIT')['values'] as $value) {
            $byId[$value['id']] = $value['name']['synonyms'] ?? [];
        }

        // Then
        $this->assertContains('pièces', $byId['unite']);
        $this->assertContains('pièce', $byId['unite']);
        $this->assertContains('kilos', $byId['kilo']);
        $this->assertContains('grammes', $byId['gramme']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogueTypes(): iterable
    {
        yield 'INGREDIENT' => ['INGREDIENT'];
        yield 'DEPARTMENT' => ['DEPARTMENT'];
        yield 'UNIT' => ['UNIT'];
        yield 'OPTION_RANK' => ['OPTION_RANK'];
        yield 'KIND' => ['KIND'];
    }

    #[DataProvider('catalogueTypes')]
    public function testEveryValueAndSynonymOfATypeIsUniqueWithoutDigitsParenthesesOrCapitalsAndShortEnough(string $typeName): void
    {
        // Given : les valeurs et leurs synonymes (Amazon refuse chiffres et parenthèses dans un type personnalisé)
        $forms = [];
        foreach ($this->type($typeName)['values'] as $value) {
            $forms[] = $value['name']['value'];
            array_push($forms, ...($value['name']['synonyms'] ?? []));
        }

        // Then
        $this->assertNotEmpty($forms);
        foreach ($forms as $form) {
            $this->assertSame(trim($form), $form, $typeName);
            $this->assertNotSame('', $form, $typeName);
            $this->assertLessThanOrEqual(self::MAX_VALUE_LENGTH, mb_strlen($form), "$typeName : « $form »");
            $this->assertDoesNotMatchRegularExpression('/[\d()&]/u', $form, "$typeName : « $form » : chiffre, parenthèse ou esperluette");
            $this->assertSame(mb_strtolower($form), $form, "$typeName : « $form » en minuscules");
        }
        $this->assertSame(array_values(array_unique($forms)), $forms, "$typeName : une valeur ou un synonyme est écrit deux fois");
    }

    public function testTheGeneratorScriptIsVersionedAndReadsTheSourcesOfTheModel(): void
    {
        // Given
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/voice/skill/generate-model.mjs');

        // Then : le modèle n'a aucune valeur écrite à la main, et le script dit d'où elles viennent
        $this->assertStringContainsString('base-ingredients.tsv', $script);
        $this->assertStringContainsString('department.json', $script);
        $this->assertStringContainsString('unit.json', $script);
        $this->assertStringContainsString('--check', $script);
        $this->assertFileExists(dirname(__DIR__, 2) . '/src/Repas/Infrastructure/DataFixture/Data/department.json');
    }

    public function testTheBuiltInIntentsCarryNoSamplesOfTheirOwn(): void
    {
        foreach ($this->languageModel()['intents'] as $intent) {
            if (str_starts_with($intent['name'], 'AMAZON.')) {
                $this->assertSame([], $intent['samples'], $intent['name']);
                $this->assertArrayNotHasKey('slots', $intent, $intent['name']);
            }
        }
    }

    public function testTheIngredientValuesAreUniqueWithoutDigitsOrParentheses(): void
    {
        // Given
        $values = $this->ingredientValues();

        // Then
        $this->assertNotEmpty($values);
        $normalized = [];
        foreach ($values as $value) {
            $this->assertSame(trim($value), $value, "« $value » : espaces autour");
            $this->assertNotSame('', $value);
            $this->assertLessThanOrEqual(self::MAX_VALUE_LENGTH, mb_strlen($value), "« $value » dépasse 140 caractères");
            $this->assertDoesNotMatchRegularExpression('/[\d()]/u', $value, "« $value » : Amazon refuse chiffres et parenthèses dans un type personnalisé");
            $normalized[] = mb_strtolower($value);
        }
        $this->assertSame(array_values(array_unique($normalized)), $normalized, 'valeurs uniques (sans tenir compte de la casse)');
    }

    public function testTheIngredientTypeCoversTheNamesOfTheBaseCatalogueThatAmazonAccepts(): void
    {
        // Given : le classement validé des ingrédients de base ; les noms avec chiffre ou parenthèse sont écrits autrement dans le modèle
        $values = array_map('mb_strtolower', $this->ingredientValues());
        $names = $this->baseCatalogueNames();

        // Then
        $this->assertGreaterThan(200, count($names), 'le catalogue de base n\'est plus lu');
        foreach ($names as $name) {
            if (preg_match('/[\d()]/u', $name) === 1) {
                continue;
            }
            $this->assertContains(mb_strtolower($name), $values, "« $name » est un ingrédient de base absent du type INGREDIENT");
        }
    }

    public function testTheManifestPointsAtTheWebhookRouteAndAsksForNoAccessToAmazonLists(): void
    {
        // Given
        self::bootKernel();
        $manifest = self::decode(self::MANIFEST_PATH)['manifest'];
        $route = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('api_voice_alexa');

        // Then : la route du webhook, en HTTPS, certificat d'une autorité reconnue
        $endpoint = $manifest['apis']['custom']['endpoint'];
        $this->assertNotNull($route);
        $this->assertSame('https', parse_url($endpoint['uri'], PHP_URL_SCHEME));
        $this->assertSame($route->getPath(), parse_url($endpoint['uri'], PHP_URL_PATH));
        $this->assertSame(['POST'], $route->getMethods());
        $this->assertSame('Trusted', $endpoint['sslCertificateType']);

        // Then : aucun accès à la liste de courses d'Amazon (retirée aux tiers), aucune permission
        $this->assertSame([], $manifest['permissions'] ?? [], 'le skill ne demande aucune permission');
        $this->assertArrayNotHasKey('householdList', $manifest['apis']);

        // Then : en français, avec des phrases d'exemple qui contiennent le nom d'invocation du modèle
        $french = $manifest['publishingInformation']['locales']['fr-FR'];
        $this->assertNotSame('', $french['name']);
        $this->assertNotSame('', $french['description']);
        foreach ($french['examplePhrases'] as $phrase) {
            $this->assertStringContainsString($this->languageModel()['invocationName'], mb_strtolower($phrase), $phrase);
        }
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return array<string, mixed>
     */
    private static function model(): array
    {
        return self::decode(self::MODEL_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    private function languageModel(): array
    {
        return self::model()['interactionModel']['languageModel'];
    }

    /**
     * @return array<string, mixed>
     */
    private function intent(string $name): array
    {
        foreach ($this->languageModel()['intents'] as $intent) {
            if ($intent['name'] === $name) {
                return $intent;
            }
        }

        $this->fail("Intention $name absente du modèle.");
    }

    /**
     * @return list<string>
     */
    private function ingredientValues(): array
    {
        foreach ($this->languageModel()['types'] as $type) {
            if ($type['name'] === 'INGREDIENT') {
                return array_map(static fn(array $value): string => $value['name']['value'], $type['values']);
            }
        }

        $this->fail('Le type INGREDIENT est absent du modèle.');
    }

    /**
     * @return array<string, mixed> le type de valeurs du modèle
     */
    private function type(string $name): array
    {
        foreach ($this->languageModel()['types'] as $type) {
            if ($type['name'] === $name) {
                return $type;
            }
        }

        $this->fail("Le type $name est absent du modèle.");
    }

    /**
     * @return list<string> les slugs du fichier de fixtures du catalogue commun (`department.json`, `unit.json`)
     */
    private function fixtureSlugs(string $file): array
    {
        $items = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/src/Repas/Infrastructure/DataFixture/Data/' . $file), true, 512, JSON_THROW_ON_ERROR);

        return array_map(static fn(array $item): string => $item['slug'], $items);
    }

    /**
     * @return list<string> la colonne `nom` du classement validé des ingrédients de base
     */
    private function baseCatalogueNames(): array
    {
        $lines = file(dirname(__DIR__, 2) . self::CATALOGUE_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        array_shift($lines);

        return array_map(static fn(string $line): string => explode("\t", $line)[1], $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $path): array
    {
        $content = file_get_contents(dirname(__DIR__, 2) . $path);
        self::assertNotFalse($content, $path);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
