<?php

namespace Repas\Tests\Voice;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\VoiceReplies;

/**
 * Les scénarios de bout en bout du skill (`docs/voice/skill/simulation-scenarios.fr-FR.json`, VOX-308) restent EN LECTURE SEULE et d'accord avec le modèle et
 * les textes d'Alexa, SANS appeler Amazon : le script `simulation-check.mjs` les rejoue contre le vrai webhook du propriétaire quand le fil principal le lance
 * (simulation d'Amazon), ce test garde le fichier sûr et cohérent. Il garde surtout le garde-fou d'écriture : aucun scénario ne peut dire une phrase qui écrit, et la
 * liste des intentions de lecture seule du script est exactement celle qu'on justifie ici.
 */
class DocsVoiceSimulationScenariosTest extends TestCase
{
    private const string MODEL_PATH = '/docs/voice/skill/interaction-model.fr-FR.json';
    private const string SCENARIOS_PATH = '/docs/voice/skill/simulation-scenarios.fr-FR.json';
    private const string SCRIPT_PATH = '/docs/voice/skill/simulation-check.mjs';

    /** Ce que chaque intention de lecture seule fait vraiment (`AlexaDialogue`, `GetSpokenItemInList`) : aucune n'écrit dans la liste. */
    private const array READ_ONLY_INTENTS = [
        'CheckItemIntent' => 'une lecture (GetSpokenItemInList)',
        'AMAZON.HelpIntent' => 'une phrase fixe',
        'AMAZON.FallbackIntent' => 'une phrase fixe',
        'AMAZON.StopIntent' => '« À bientôt. »',
        'AMAZON.CancelIntent' => '« À bientôt. »',
        'AMAZON.YesIntent' => 'sans question en cours : on invite à ajouter',
        'AMAZON.NoIntent' => 'sans question en cours : au revoir',
    ];

    /** Les intentions qui écrivent ou qui répondent à une question (donc pourraient écrire) : jamais dans la liste de lecture seule. */
    private const array WRITING_OR_ANSWERING_INTENTS = [
        'AddItemIntent', 'RemoveItemIntent', 'AddMealIntent', 'RemoveMealIntent', 'CreateItemIntent', 'ChooseOptionIntent', 'ChooseKindIntent',
        'AnswerDepartmentIntent', 'AnswerUnitIntent', 'AnswerServingsIntent', 'KeepMealIntent', 'ListChoicesIntent',
    ];

    /** Les verbes qui écrivent : aucune phrase dite dans un scénario ne les contient. */
    private const string WRITING_VERBS = '/(?<![\p{L}])(ajoute|ajouter|rajoute|mets|mettre|note|enlève|enlever|retire|retirer|déduis|supprime|supprimer|ôte|planifie|planifier|prévois|prévoir|prépare|préparer|crée|créer)(?![\p{L}])/u';

    private const array STATIC_REPLIES = ['LAUNCH', 'HELP', 'FALLBACK', 'GOODBYE'];

    private const array TURN_KEYS = ['say', 'heard', 'sessionMode', 'launch', 'intent', 'slots', 'replyMatches', 'replyConstants', 'replyExamples', 'sessionEnds', 'reprompt', 'sessionNew'];

    public function testEveryScenarioIsReadOnlyWellFormedAndUnique(): void
    {
        // Given
        $file = self::decode(self::SCENARIOS_PATH);

        // Then
        $this->assertSame('fr-FR', $file['locale']);
        $this->assertNotEmpty($file['description']);
        $scenarios = $file['scenarios'];
        $this->assertGreaterThanOrEqual(12, count($scenarios));
        $this->assertLessThanOrEqual(20, count($scenarios));
        $ids = [];
        foreach ($scenarios as $scenario) {
            $id = $scenario['id'] ?? '(sans identifiant)';
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id, 'identifiant en minuscules, séparé par des tirets');
            $this->assertArrayNotHasKey($id, $ids, "$id : identifiant en double");
            $ids[$id] = true;
            $this->assertNotSame('', trim((string) ($scenario['note'] ?? '')), "$id : dire pourquoi ce scénario existe");
            // Le garde-fou (a) : « writes » est EXACTEMENT false, partout
            $this->assertArrayHasKey('writes', $scenario, "$id : « writes » manque");
            $this->assertFalse($scenario['writes'], "$id : un scénario de ce fichier n'écrit RIEN (il appelle le vrai serveur du propriétaire)");
            $this->assertSame([], array_diff(array_keys($scenario), ['id', 'note', 'writes', 'turns']), "$id : clé inconnue");
            $this->assertNotEmpty($scenario['turns'], "$id : aucun tour");
        }
    }

    public function testEveryTurnSaysAReadOnlyThingAndOpensTheSessionTheRightWay(): void
    {
        // Given
        $model = $this->languageModel();
        $slotsOf = [];
        foreach ($model['intents'] as $intent) {
            $slotsOf[$intent['name']] = array_map(static fn(array $slot): string => $slot['name'], $intent['slots'] ?? []);
        }

        foreach (self::decode(self::SCENARIOS_PATH)['scenarios'] as $scenario) {
            $previousEnded = true;
            foreach ($scenario['turns'] as $index => $turn) {
                $where = "{$scenario['id']} tour " . ($index + 1);
                $this->assertSame([], array_diff(array_keys($turn), self::TURN_KEYS), "$where : clé inconnue");
                foreach (['say', 'sessionMode', 'intent', 'replyMatches', 'sessionEnds'] as $required) {
                    $this->assertArrayHasKey($required, $turn, "$where : « $required » manque");
                }
                $say = $turn['say'];

                // Les phrases : en minuscules, sans ponctuation, sans verbe qui écrit
                $this->assertMatchesRegularExpression("/^[\\p{Ll}0-9' ]+$/u", $say, "$where : « $say » : minuscules, sans ponctuation (apostrophe permise)");
                $this->assertDoesNotMatchRegularExpression(self::WRITING_VERBS, $say, "$where : « $say » contient un verbe qui écrit");

                // La séance : la première est neuve, la suivante continue la séance ouverte, sauf si la réponse précédente l'a fermée
                $this->assertContains($turn['sessionMode'], ['FORCE_NEW_SESSION', 'DEFAULT'], $where);
                if ($index === 0 || $previousEnded) {
                    $this->assertSame('FORCE_NEW_SESSION', $turn['sessionMode'], "$where : la séance est neuve");
                } else {
                    $this->assertSame('DEFAULT', $turn['sessionMode'], "$where : elle continue la séance ouverte");
                }
                $this->assertIsBool($turn['sessionEnds'], $where);
                $previousEnded = $turn['sessionEnds'];

                // Le lancement (« ouvre jarvis ») : seul tour sans profileur, toujours le premier
                if (($turn['launch'] ?? false) === true) {
                    $this->assertSame(0, $index, "$where : le lancement ouvre le scénario");
                    $this->assertMatchesRegularExpression('/^(ouvre|lance) jarvis$/', $say, $where);
                    $this->assertSame('LaunchRequest', $turn['intent'], $where);
                    $this->assertArrayNotHasKey('heard', $turn, "$where : le lancement n'a pas de phrase à profiler");
                    $this->assertArrayNotHasKey('slots', $turn, $where);
                } else {
                    // Le garde-fou (b) : la phrase passe d'abord au profileur, SANS l'invocation
                    $this->assertArrayNotHasKey('launch', $turn, "$where : « launch » est vrai ou absent");
                    $this->assertContains($turn['intent'], array_keys(self::READ_ONLY_INTENTS), "$where : « {$turn['intent']} » n'est pas une intention de lecture seule");
                    $heard = $turn['heard'] ?? '';
                    $this->assertNotSame('', $heard, "$where : « heard » (la phrase après l'invocation) manque");
                    $this->assertMatchesRegularExpression("/^[\\p{Ll}0-9' ]+$/u", $heard, $where);
                    $this->assertDoesNotMatchRegularExpression('/\b(jarvis|alexa)\b/u', $heard, "$where : l'invocation n'est pas dans la phrase du profileur");
                    if ($turn['sessionMode'] === 'DEFAULT') {
                        $this->assertSame($heard, $say, "$where : dans une séance ouverte on ne redit pas l'invocation");
                    } else {
                        $this->assertSame("demande à jarvis $heard", $say, "$where : en appel direct, « demande à jarvis » puis la phrase profilée");
                    }
                    // Les créneaux attendus existent dans l'intention
                    foreach ($turn['slots'] ?? [] as $name => $value) {
                        $this->assertContains($name, $slotsOf[$turn['intent']] ?? [], "$where : l'intention {$turn['intent']} n'a pas de créneau « $name »");
                        $this->assertTrue($value === null || (is_string($value) && trim($value) !== ''), "$where : créneau $name : null ou un texte");
                    }
                }
                foreach (['reprompt', 'sessionNew'] as $flag) {
                    if (array_key_exists($flag, $turn)) {
                        $this->assertIsBool($turn[$flag], "$where : $flag");
                    }
                }
            }
        }
    }

    public function testTheReplyPatternsAcceptTheRealTextsAndNeverTheFailure(): void
    {
        // Given : les textes qu'Alexa ne doit JAMAIS dire dans un scénario réussi
        $never = [VoiceReplies::FAILURE, VoiceReplies::SLOW_DOWN, VoiceReplies::UNSUPPORTED_LANGUAGE, VoiceReplies::LINK_ACCOUNT, VoiceReplies::NOT_UNDERSTOOD];

        foreach (self::decode(self::SCENARIOS_PATH)['scenarios'] as $scenario) {
            foreach ($scenario['turns'] as $index => $turn) {
                $where = "{$scenario['id']} tour " . ($index + 1);
                $pattern = $turn['replyMatches'];
                $this->assertStringNotContainsString('~', $pattern, "$where : « ~ » sert de délimiteur au test");
                $this->assertNotFalse(@preg_match("~$pattern~u", ''), "$where : expression régulière invalide");
                $this->assertGreaterThan(0, strlen($pattern));

                // Les textes réels cités
                $constants = $turn['replyConstants'] ?? [];
                $this->assertNotEmpty($constants, "$where : « replyConstants » dit quels textes de VoiceReplies l'expression doit reconnaître");
                foreach ($constants as $name) {
                    $this->assertTrue(defined(VoiceReplies::class . '::' . $name), "$where : VoiceReplies::$name n'existe pas");
                    $this->assertSame(1, preg_match("~$pattern~u", constant(VoiceReplies::class . '::' . $name)), "$where : /$pattern/ ne reconnaît pas VoiceReplies::$name");
                }
                foreach ($turn['replyExamples'] ?? [] as $example) {
                    $this->assertSame(1, preg_match("~$pattern~u", $example), "$where : /$pattern/ ne reconnaît pas « $example »");
                }
                foreach ($never as $text) {
                    $this->assertSame(0, preg_match("~$pattern~u", $text), "$where : /$pattern/ accepterait « $text »");
                }

                // Une phrase fixe n'est reconnue que par son expression : LAUNCH ne passe pas pour HELP
                $cited = array_values(array_intersect($constants, self::STATIC_REPLIES));
                if ($cited !== []) {
                    $matched = array_values(array_filter(self::STATIC_REPLIES, static fn(string $name): bool => preg_match("~$pattern~u", constant(VoiceReplies::class . '::' . $name)) === 1));
                    $this->assertSame($cited, $matched, "$where : l'expression doit reconnaître exactement " . implode(', ', $cited) . ' parmi les phrases fixes');
                }
            }
        }
    }

    public function testTheScriptsReadOnlyIntentsAreExactlyTheJustifiedOnes(): void
    {
        // Given : la liste que le script utilise pour BLOQUER un tour, lue dans sa source
        $source = (string) file_get_contents($this->root() . self::SCRIPT_PATH);
        $this->assertSame(1, preg_match('/READ_ONLY_INTENTS = new Set\(\[(.*?)\]\)/s', $source, $match), 'READ_ONLY_INTENTS est introuvable dans le script');
        preg_match_all("/'([^']+)'/", $match[1], $names);
        $intents = array_map(static fn(array $intent): string => $intent['name'], $this->languageModel()['intents']);

        // Then : exactement la liste justifiée, que des intentions du modèle, et aucune qui écrit ou répond à une question
        $this->assertEqualsCanonicalizing(array_keys(self::READ_ONLY_INTENTS), $names[1]);
        foreach ($names[1] as $name) {
            $this->assertContains($name, $intents, "$name : intention inconnue du modèle");
            $this->assertNotContains($name, self::WRITING_OR_ANSWERING_INTENTS, "$name écrit ou répond à une question : jamais en lecture seule");
        }
        // Le script refuse aussi un scénario qui n'est pas « writes: false » (garde (a)) et arrête tout sur une intention inattendue (garde (c)) ou un dialogue ouvert (garde (d))
        $this->assertStringContainsString('--allow-writes', $source);
        $this->assertStringContainsString('ARRÊT IMMÉDIAT', $source);
        $this->assertStringContainsString('dialogueOpen', $source);
    }

    public function testNoScenarioTouchesTheSessionTokenOrTheListOutsideTheReadOnlyIntents(): void
    {
        // Given : le fichier brut, sans rien d'autre que les phrases, les attendus et les notes
        $raw = (string) file_get_contents($this->root() . self::SCENARIOS_PATH);

        // Then : aucun jeton, identifiant de skill ou d'utilisateur Amazon dans le fichier
        $this->assertStringNotContainsString('amzn1.', $raw);
        $this->assertDoesNotMatchRegularExpression('/accessToken|Bearer /i', $raw);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
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
        $raw = file_get_contents(dirname(__DIR__, 2) . $path);
        self::assertNotFalse($raw, "$path illisible");

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
