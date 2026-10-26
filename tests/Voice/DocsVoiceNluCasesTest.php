<?php

namespace Repas\Tests\Voice;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\AlexaIntent;

/**
 * Les cas de compréhension d'Alexa (`docs/voice/skill/nlu-cases.fr-FR.json`, VOX-307) restent d'accord avec le modèle d'interaction, SANS appeler Amazon :
 * le script `nlu-check.mjs` les rejoue contre le profileur d'énoncés quand le propriétaire le lance, ce test garde seulement le fichier cohérent
 * (intentions, créneaux et identifiants qui existent, phrases uniques, chaque intention couverte, les pannes réelles présentes).
 *
 * Il fige aussi les AMBIGUÏTÉS des phrases d'exemple du modèle (l'API de détection de conflits d'Amazon n'existe plus) : une phrase d'exemple une fois
 * ses créneaux masqués (`{item}` → `{}`) qui appartient à deux intentions, ou qui devient celle d'une autre intention quand on lui retire un créneau. Les
 * ambiguïtés connues sont voulues et listées ci-dessous avec leur raison ; une nouvelle, accidentelle, fait échouer le test.
 */
class DocsVoiceNluCasesTest extends TestCase
{
    private const string MODEL_PATH = '/docs/voice/skill/interaction-model.fr-FR.json';
    private const string CASES_PATH = '/docs/voice/skill/nlu-cases.fr-FR.json';

    private const array CASE_KEYS = ['id', 'utterance', 'intent', 'slots', 'note', 'alsoIntents', 'knownGap'];

    /**
     * Les phrases d'exemple identiques entre intentions (une fois les créneaux masqués), VOULUES : Alexa choisit l'intention sans connaître l'état de notre
     * dialogue (« persil » arrive comme un produit ou comme une réponse), c'est le webhook qui lit la réponse d'après l'ÉTAT (`SpokenAnswerInterpreter`).
     */
    private const array SHARED_SAMPLES = [
        '{}' => ['AddItemIntent', 'AnswerDepartmentIntent', 'AnswerServingsIntent', 'AnswerUnitIntent', 'ChooseKindIntent', 'ChooseOptionIntent'],
        'des {}' => ['AddItemIntent', 'AnswerUnitIntent'],
        'la {}' => ['ChooseKindIntent', 'ChooseOptionIntent'],
        'une {}' => ['AnswerUnitIntent', 'ChooseKindIntent'],
    ];

    /**
     * Les phrases d'exemple qui deviennent celles d'une AUTRE intention quand on leur retire un créneau (« deux croquettes » sans le nombre est « croquettes »,
     * une phrase à un seul créneau d'une réponse de dialogue) : la même raison que ci-dessus.
     */
    private const array SLOT_FEWER = [
        'AddItemIntent>AnswerDepartmentIntent:{} {}',
        'AddItemIntent>AnswerServingsIntent:{} {}',
        'AddItemIntent>AnswerUnitIntent:{} {}',
        'AddItemIntent>ChooseKindIntent:{} {}',
        'AddItemIntent>ChooseOptionIntent:{} {}',
    ];

    /** Les phrases RÉELLES du propriétaire (essais du 2026-10-25 et 26) : elles doivent rester dans les cas. */
    private const array REAL_SESSION_UTTERANCES = [
        'ajoute un paquet de croquette',
        'ajoute deux paquets de croquettes',
        'ajoute 10 croquettes',
        'ajoute du pain',
        'ajoute des tomates',
        'ajoute de la ciboulette',
        'ajoute des patates',
        'planifier une fondue',
        'la recette fondue',
        'retire un paquet de croquettes',
        'enlève du pain',
        'est ce qu\'il y a des croquettes dans la liste',
        'ajoute un hachis parmentier',
    ];

    public function testTheCasesAreWellFormedAndUnique(): void
    {
        // Given
        $file = self::decode(self::CASES_PATH);

        // Then
        $this->assertSame('fr-FR', $file['locale']);
        $this->assertNotEmpty($file['description']);
        $cases = $file['cases'];
        $this->assertGreaterThanOrEqual(80, count($cases), 'une centaine de cas environ');
        $ids = [];
        $utterances = [];
        foreach ($cases as $case) {
            $id = $case['id'] ?? '(sans identifiant)';
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id, 'identifiant en minuscules, séparé par des tirets');
            $this->assertSame([], array_diff(array_keys($case), self::CASE_KEYS), "$id : clé inconnue");
            foreach (['id', 'utterance', 'intent', 'slots', 'note'] as $required) {
                $this->assertArrayHasKey($required, $case, "$id : « $required » manque");
            }
            $this->assertIsString($case['utterance']);
            $this->assertSame(trim($case['utterance']), $case['utterance'], "$id : espaces en trop");
            $this->assertMatchesRegularExpression("/^[\\p{Ll}0-9' ]+$/u", $case['utterance'], "$id : « {$case['utterance']} » : minuscules, sans ponctuation (apostrophe permise)");
            $this->assertDoesNotMatchRegularExpression('/\b(jarvis|alexa)\b/u', $case['utterance'], "$id : l'invocation n'est pas dans la phrase, le profileur la reçoit après");
            $this->assertNotSame('', trim((string) $case['note']), "$id : dire pourquoi ce cas existe");
            if (array_key_exists('knownGap', $case)) {
                $this->assertIsBool($case['knownGap'], "$id : knownGap est vrai ou absent");
            }
            $this->assertArrayNotHasKey($id, $ids, "$id : identifiant en double");
            $ids[$id] = true;
            $this->assertArrayNotHasKey($case['utterance'], $utterances, "$id : phrase en double « {$case['utterance']} »");
            $utterances[$case['utterance']] = true;
        }
    }

    public function testEveryCaseNamesAnExistingIntentSlotAndIdentifierOfTheModel(): void
    {
        // Given
        $model = $this->languageModel();
        $slotTypes = [];
        foreach ($model['intents'] as $intent) {
            foreach ($intent['slots'] ?? [] as $slot) {
                $slotTypes[$intent['name']][$slot['name']] = $slot['type'];
            }
        }
        $idsOfType = [];
        foreach ($model['types'] as $type) {
            $idsOfType[$type['name']] = array_values(array_filter(array_map(static fn(array $value): ?string => $value['id'] ?? null, $type['values'])));
        }
        $intents = array_map(static fn(array $intent): string => $intent['name'], $model['intents']);

        foreach (self::decode(self::CASES_PATH)['cases'] as $case) {
            $id = $case['id'];

            // Then : l'intention
            $this->assertContains($case['intent'], $intents, "$id : intention inconnue du modèle");
            foreach ($case['alsoIntents'] ?? [] as $also) {
                $this->assertContains($also, $intents, "$id : intention admise inconnue du modèle");
                $this->assertNotSame($case['intent'], $also, "$id : l'intention attendue n'est pas aussi une autre admise");
            }

            // … ses créneaux, et la forme de ce qu'on en attend
            foreach ($case['slots'] as $name => $spec) {
                $this->assertArrayHasKey($name, $slotTypes[$case['intent']] ?? [], "$id : l'intention {$case['intent']} n'a pas de créneau « $name »");
                if ($spec === null) {
                    continue;
                }
                $this->assertIsArray($spec, "$id : créneau $name : null ou {\"value\"} ou {\"id\"}");
                $this->assertCount(1, $spec, "$id : créneau $name : une seule clé, « value » ou « id »");
                if (array_key_exists('value', $spec)) {
                    $this->assertIsString($spec['value'], "$id : créneau $name");
                    $this->assertNotSame('', trim($spec['value']), "$id : créneau $name : valeur vide (écrire null pour « doit rester vide »)");
                } else {
                    $this->assertArrayHasKey('id', $spec, "$id : créneau $name : « value » ou « id »");
                    $type = $slotTypes[$case['intent']][$name];
                    $this->assertNotEmpty($idsOfType[$type] ?? [], "$id : le type $type n'a pas d'identifiants : juger le créneau $name par « value »");
                    $this->assertContains((string) $spec['id'], $idsOfType[$type], "$id : l'identifiant « {$spec['id']} » n'existe pas dans le type $type");
                }
            }
        }
    }

    public function testEveryIntentTheWebhookHandlesHasAtLeastOneCase(): void
    {
        // Given
        $covered = array_unique(array_map(static fn(array $case): string => $case['intent'], self::decode(self::CASES_PATH)['cases']));

        // Then
        foreach (AlexaIntent::cases() as $intent) {
            $this->assertContains($intent->value, $covered, "{$intent->value} n'a aucun cas de compréhension");
        }
    }

    public function testTheRealSessionOfTheOwnerIsKept(): void
    {
        // Given
        $utterances = array_map(static fn(array $case): string => $case['utterance'], self::decode(self::CASES_PATH)['cases']);

        // Then : les phrases des essais réels (leurs pannes ont toutes une raison dans la note du cas)
        foreach (self::REAL_SESSION_UTTERANCES as $real) {
            $this->assertContains($real, $utterances, "la phrase réelle « $real » ne doit pas quitter les cas");
        }
    }

    public function testTheKnownAmbiguitiesOfTheSamplesAreTheDeclaredOnesAndNoOther(): void
    {
        // Given : chaque phrase d'exemple, créneaux masqués, avec les intentions qui la portent
        $byMasked = [];
        foreach ($this->languageModel()['intents'] as $intent) {
            foreach ($intent['samples'] ?? [] as $sample) {
                $byMasked[self::mask($sample)][$intent['name']] = true;
            }
        }

        // When
        $shared = [];
        foreach ($byMasked as $masked => $intents) {
            if (count($intents) > 1) {
                $names = array_keys($intents);
                sort($names);
                $shared[$masked] = $names;
            }
        }
        $expected = self::SHARED_SAMPLES;
        ksort($shared);
        ksort($expected);

        // Then : aucune phrase d'exemple n'est partagée sans raison, et la liste voulue ne contient rien d'obsolète
        $this->assertSame($expected, $shared, 'une phrase d\'exemple appartient à deux intentions : l\'ajouter à SHARED_SAMPLES avec sa raison, ou la retirer du modèle');
    }

    public function testNoSampleBecomesTheSampleOfAnotherIntentWhenASlotIsDroppedExceptTheDeclaredOnes(): void
    {
        // Given
        $byMasked = [];
        foreach ($this->languageModel()['intents'] as $intent) {
            foreach ($intent['samples'] ?? [] as $sample) {
                $byMasked[self::mask($sample)][$intent['name']] = true;
            }
        }

        // When : on retire un créneau à chaque phrase et on regarde si une autre intention porte le résultat
        $found = [];
        foreach ($this->languageModel()['intents'] as $intent) {
            foreach ($intent['samples'] ?? [] as $sample) {
                preg_match_all('/\{[a-z]+\}/', $sample, $slots, PREG_OFFSET_CAPTURE);
                foreach ($slots[0] as [$slot, $offset]) {
                    $without = self::mask(trim((string) preg_replace('/\s+/', ' ', substr($sample, 0, $offset) . substr($sample, $offset + strlen($slot)))));
                    foreach (array_keys($byMasked[$without] ?? []) as $other) {
                        if ($other !== $intent['name']) {
                            $found["{$intent['name']}>$other:" . self::mask($sample)] = true;
                        }
                    }
                }
            }
        }
        $found = array_keys($found);
        sort($found);
        $expected = self::SLOT_FEWER;
        sort($expected);

        // Then
        $this->assertSame($expected, $found, 'une phrase d\'exemple devient celle d\'une autre intention sans un créneau : la déclarer dans SLOT_FEWER avec sa raison, ou la retirer');
    }

    private static function mask(string $sample): string
    {
        return (string) preg_replace('/\{[a-z]+\}/', '{}', $sample);
    }

    /**
     * @return array<string, mixed>
     */
    private function languageModel(): array
    {
        return self::decode(self::MODEL_PATH)['interactionModel']['languageModel'];
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
