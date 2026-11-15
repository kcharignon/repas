<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Voice\Application\Loader\VoiceTraceLoader;
use Repas\Voice\Domain\Interface\VoiceTraceRepository;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\TracedFailure;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Domain\Service\AlexaTraceSanitizer;
use RuntimeException;

/**
 * La lecture de la trace des échanges avec Alexa sur la VRAIE base (ADR 0029 décision 14) : l'ordre, les filtres, la limite, le texte dit par Alexa, le résumé des
 * créneaux entendus et de ce qu'Amazon en a reconnu, et l'échange en entier. Une seule requête par lecture.
 */
class VoiceTraceLoaderTest extends DatabaseTestCase
{
    private VoiceTraceLoader $loader;
    private VoiceTraceRepository $repository;
    private AlexaTraceSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(VoiceTraceLoader::class);
        $this->repository = static::getContainer()->get(VoiceTraceRepository::class);
        $this->sanitizer = new AlexaTraceSanitizer();
    }

    public function testTheLatestComeFirstAndTheLimitIsKept(): void
    {
        // Given
        foreach ([1, 2, 3, 4] as $duration) {
            $this->save(AlexaTestRequest::launch(), 'Salut.', durationMs: $duration);
        }

        // When
        $traces = $this->loader->recent(3, null, false, null);

        // Then : le plus récent d'abord
        $this->assertSame([4, 3, 2], array_map(static fn($trace): int => $trace->durationMs, $traces));
    }

    public function testTheLimitIsBoundedFromOneToTwoHundred(): void
    {
        // Given
        $this->save(AlexaTestRequest::launch(), 'Salut.');
        $this->save(AlexaTestRequest::launch(), 'Salut.');

        // Then : zéro ou négatif vaut un, et rien ne dépasse la borne
        $this->assertCount(1, $this->loader->recent(0, null, false, null));
        $this->assertCount(1, $this->loader->recent(-5, null, false, null));
        $this->assertCount(2, $this->loader->recent(100000, null, false, null));
    }

    public function testTheFiltersAreCombined(): void
    {
        // Given : un ajout réussi, un ajout en panne, un retrait en panne, un lancement
        $this->save(AlexaTestRequest::addItem('du pain'), 'Noté.');
        $this->save(AlexaTestRequest::addItem('du pain'), "Je n'ai pas pu faire ça.", TraceOutcome::FAILED, new RuntimeException('panne'));
        $this->save(AlexaTestRequest::removeItem('du pain'), "Je n'ai pas pu faire ça.", TraceOutcome::FAILED, new RuntimeException('panne'));
        $this->save(AlexaTestRequest::launch(), 'Salut.');

        // Then
        $this->assertCount(2, $this->loader->recent(20, 'AddItemIntent', false, null));
        $this->assertCount(2, $this->loader->recent(20, null, true, null));
        $this->assertCount(1, $this->loader->recent(20, 'AddItemIntent', true, null));
        $this->assertCount(0, $this->loader->recent(20, 'CheckItemIntent', false, null));
        $this->assertCount(4, $this->loader->recent(20, null, false, null));
    }

    public function testOnlyWhatWasSavedSinceTheGivenInstantIsRead(): void
    {
        // Given
        $this->save(AlexaTestRequest::launch(), 'Il y a longtemps.', at: new DateTimeImmutable('2026-11-15 08:00:00'));
        $this->save(AlexaTestRequest::launch(), 'Tout à l\'heure.', at: new DateTimeImmutable('2026-11-15 11:59:59'));
        $this->save(AlexaTestRequest::launch(), 'Maintenant.', at: new DateTimeImmutable('2026-11-15 12:00:00'));

        // When
        $traces = $this->loader->recent(20, null, false, new DateTimeImmutable('2026-11-15 12:00:00'));

        // Then : l'instant donné compte
        $this->assertSame(['Maintenant.'], array_map(static fn($trace): ?string => $trace->said, $traces));
    }

    public function testWhatAlexaSaidAndWhatShePickedUpAreSummarized(): void
    {
        // Given : un ajout dit, avec un nombre sans résolution, et une unité qu'Amazon a rapprochée d'une des nôtres
        $this->save(AlexaTestRequest::addQuantified('croquettes', '3', 'paquets', 'paquet'), "C'est noté : croquettes.");

        // When
        $trace = $this->loader->recent(1, null, false, null)[0];

        // Then
        $this->assertSame("C'est noté : croquettes.", $trace->said);
        $this->assertSame('item="croquettes", unit="paquets" => paquet, quantity="3"', $trace->heard);
        $this->assertSame(['IntentRequest', 'AddItemIntent', true, 'ANSWERED', 'AddSpokenItem'], [$trace->requestType, $trace->intent, $trace->sessionNew, $trace->outcome, $trace->decision]);
        $this->assertNull($trace->dialogueStep);
        $this->assertNull($trace->failureClass);
    }

    public function testASlotAmazonDidNotRecognizeSaysSo(): void
    {
        // Given : « divers » dit à la question du rayon, en pleine question (étape « department » de la séance)
        $this->save(
            AlexaTestRequest::answerDepartment('divers', null)->withSessionAttributes(['dialogue' => ['step' => 'department', 'phrase' => 'ciboulette', 'options' => [], 'name' => 'ciboulette', 'misses' => 0]]),
            'Dans quel rayon ?',
            decision: 'AnswerSpokenDialogue:department',
        );

        // When
        $trace = $this->loader->recent(1, null, false, null)[0];

        // Then : l'entendu, l'absence de correspondance, et l'étape de la question
        $this->assertSame('department="divers" (non reconnu)', $trace->heard);
        $this->assertSame('department', $trace->dialogueStep);
        $this->assertFalse($trace->sessionNew);
    }

    public function testARequestWithoutSlotsOrWithAnUnfilledSlotHasNothingHeard(): void
    {
        // Given
        $this->save(AlexaTestRequest::launch(), 'Salut.');
        $this->save(AlexaTestRequest::addItem(null), 'Que veux-tu ajouter ?');

        // When
        $traces = $this->loader->recent(2, null, false, null);

        // Then : le créneau sans texte n'est pas écrit
        $this->assertSame([null, null], [$traces[0]->heard, $traces[1]->heard]);
    }

    public function testAnExchangeWithNoSpeechHasNoWords(): void
    {
        // Given : la fin d'une séance, réponse vide
        $this->save(AlexaTestRequest::sessionEnded(), null, TraceOutcome::SESSION_ENDED);

        // Then
        $this->assertNull($this->loader->recent(1, null, false, null)[0]->said);
    }

    public function testTheFailureClassIsInTheSummary(): void
    {
        // Given
        $this->save(AlexaTestRequest::addItem('du pain'), "Je n'ai pas pu faire ça.", TraceOutcome::FAILED, new RuntimeException('panne'));

        // Then
        $this->assertSame(RuntimeException::class, $this->loader->recent(1, null, false, null)[0]->failureClass);
    }

    public function testOneExchangeIsReadInFullWithItsRequestItsAnswerAndItsFailure(): void
    {
        // Given
        $failure = new RuntimeException('échec « é »', 0, new RuntimeException('cause'));
        $this->save(AlexaTestRequest::addItem('du pain'), "Je n'ai pas pu faire ça.", TraceOutcome::FAILED, $failure, decision: 'AddSpokenItem', userId: 'user-1', linkId: 'link-1');
        $id = $this->loader->recent(1, null, false, null)[0]->id;

        // When
        $detail = $this->loader->one($id);

        // Then
        $this->assertNotNull($detail);
        $this->assertSame([$id, 'user-1', 'link-1', 'FAILED'], [$detail->summary->id, $detail->userId, $detail->linkId, $detail->summary->outcome]);
        $this->assertSame('du pain', $detail->request['request']['intent']['slots']['item']['value']);
        $this->assertSame("Je n'ai pas pu faire ça.", $detail->response['response']['outputSpeech']['text']);
        $this->assertSame([RuntimeException::class, 'échec « é »', RuntimeException::class, 'cause'], [$detail->failure['class'], $detail->failure['message'], $detail->failure['previousClass'], $detail->failure['previousMessage']]);
    }

    public function testAnExchangeWithoutAFailureHasNone(): void
    {
        // Given
        $this->save(AlexaTestRequest::launch(), 'Salut.');
        $id = $this->loader->recent(1, null, false, null)[0]->id;

        // Then
        $this->assertNull($this->loader->one($id)->failure);
    }

    public function testAnUnknownNumberGivesNothing(): void
    {
        $this->assertNull($this->loader->one(987654321));
    }

    public function testEachReadCostsOneQueryWhateverTheNumberOfRows(): void
    {
        // Given
        foreach (range(1, 30) as $duration) {
            $this->save(AlexaTestRequest::addItem('du pain'), 'Noté.', durationMs: $duration);
        }
        $counter = static::getContainer()->get(QueryCounter::class);

        // When
        $counter->reset();
        $this->loader->recent(30, null, false, null);
        $list = $counter->count();
        $counter->reset();
        $this->loader->one($this->loader->recent(1, null, false, null)[0]->id);
        $one = $counter->count();

        // Then : la liste en une requête ; l'échange en une (plus la liste qui a donné son numéro)
        $this->assertSame(1, $list);
        $this->assertSame(2, $one);
    }

    private function save(
        AlexaTestRequest $request,
        ?string $said,
        TraceOutcome $outcome = TraceOutcome::ANSWERED,
        ?RuntimeException $failure = null,
        ?string $decision = null,
        ?string $userId = null,
        ?string $linkId = null,
        int $durationMs = 10,
        ?DateTimeImmutable $at = null,
    ): void {
        $response = $said === null ? AlexaResponse::silence() : AlexaResponse::say($said);
        $this->repository->add(VoiceTrace::record(
            $at ?? new DateTimeImmutable('2026-11-15 12:00:00'),
            $userId,
            $linkId,
            $this->sanitizer->request(json_decode($request->toJson(), true)),
            $this->sanitizer->response($response->toArray()),
            $outcome,
            $decision ?? ($failure === null ? 'AddSpokenItem' : null),
            $durationMs,
            $failure === null ? null : TracedFailure::fromThrowable($failure),
        ));
    }
}
