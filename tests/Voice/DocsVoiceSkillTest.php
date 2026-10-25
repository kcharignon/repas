<?php

namespace Repas\Tests\Voice;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Service\AlexaDialogue;
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

        // Then : deux mots au moins, en minuscules, sans le mot d'appel d'Alexa
        $invocation = $language['invocationName'];
        $this->assertIsString($invocation);
        $this->assertMatchesRegularExpression("/^[\\p{Ll}' ]+$/u", $invocation, 'minuscules et espaces seulement');
        $this->assertGreaterThanOrEqual(2, count(explode(' ', $invocation)), 'au moins deux mots');
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
        $request = $intent === 'AddItemIntent' ? AlexaTestRequest::addItem('du pain') : AlexaTestRequest::intent($intent);

        // When
        $answer = $dialogue->answer(AlexaRequest::fromDecoded($request->toArray()));

        // Then : une réponse parlée, ou (ajout d'un article compris) le cas d'usage d'ajout
        if ($intent === 'AddItemIntent') {
            $this->assertNull($answer, 'AddItemIntent avec un article est confié au cas d\'usage AddSpokenItem');
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

    public function testTheAddItemIntentHasOneItemSlotOfTheIngredientTypeAndWellFormedSamples(): void
    {
        // Given
        $intent = $this->intent('AddItemIntent');

        // Then : le créneau que le webhook lit (`request.intent.slots.item`), de notre type
        $this->assertSame([['name' => 'item', 'type' => 'INGREDIENT']], $intent['slots']);
        $this->assertContains('INGREDIENT', array_map(static fn(array $type): string => $type['name'], $this->languageModel()['types']));

        $samples = $intent['samples'];
        $this->assertGreaterThanOrEqual(5, count($samples));
        $this->assertSame(array_values(array_unique($samples)), $samples, 'aucune phrase en double');
        foreach ($samples as $sample) {
            $this->assertIsString($sample);
            $this->assertSame(1, substr_count($sample, '{item}'), $sample);
            $this->assertSame(0, substr_count(str_replace('{item}', '', $sample), '{'), "« $sample » : un seul créneau, {item}");
            $this->assertSame($sample, trim($sample));
            $this->assertDoesNotMatchRegularExpression('/\d/', $sample, "« $sample » : pas de chiffre dans une phrase d'exemple");
            $this->assertSame(mb_strtolower($sample), $sample, "« $sample » : en minuscules");
        }
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
