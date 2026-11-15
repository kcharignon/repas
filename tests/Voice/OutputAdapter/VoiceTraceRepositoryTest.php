<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Voice\Domain\Interface\VoiceTraceRepository;
use Repas\Voice\Domain\Model\TracedFailure;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Domain\Model\TracedRequest;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Domain\Service\AlexaTraceSanitizer;
use RuntimeException;

/**
 * La trace des échanges avec Alexa sur la VRAIE base (ADR 0029 décision 14) : ce qui s'écrit, les 1 000 plus récents seulement, et le nombre d'instructions
 * SQL, constant quel que soit le nombre de lignes.
 */
class VoiceTraceRepositoryTest extends DatabaseTestCase
{
    private VoiceTraceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(VoiceTraceRepository::class);
    }

    public function testAnExchangeIsWrittenWithEveryField(): void
    {
        // Given : une requête assainie (« divers » non reconnu), une réponse accentuée et une panne
        $sanitizer = new AlexaTraceSanitizer();
        $request = $sanitizer->request(json_decode(AlexaTestRequest::answerDepartment('divers', null)->toJson(), true));
        $response = $sanitizer->response(['version' => '1.0', 'response' => ['outputSpeech' => ['type' => 'PlainText', 'text' => 'Dans quel rayon, déjà ?'], 'shouldEndSession' => false]]);
        $failure = TracedFailure::fromThrowable(new RuntimeException('échec « é »', 0, new RuntimeException('cause')));

        // When
        $this->repository->add(VoiceTrace::record(new DateTimeImmutable('2026-11-15 12:30:45'), 'user-1', 'link-1', $request, $response, TraceOutcome::FAILED, 'AnswerSpokenDialogue:department', 87, $failure));

        // Then
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM voice_trace');
        $this->assertSame(['2026-11-15 12:30:45', 'user-1', 'link-1', 'IntentRequest', 'AnswerDepartmentIntent', true, 'FAILED', 'AnswerSpokenDialogue:department', 87], [
            $row['created_at'], $row['user_id'], $row['link_id'], $row['request_type'], $row['intent'], $row['session_new'], $row['outcome'], $row['decision'], $row['duration_ms'],
        ]);
        // JSONB ne garde pas l'ordre des clés : on compare le contenu
        $this->assertEquals($request->payload, json_decode($row['request'], true));
        $this->assertEquals($response, json_decode($row['response'], true));
        $this->assertEquals($failure->toArray(), json_decode($row['failure'], true));
        $this->assertSame('échec « é »', json_decode($row['failure'], true)['message'], 'les accents restent des accents');
    }

    public function testAnExchangeWithoutAccountNorFailureNorSessionIsWrittenWithNulls(): void
    {
        // When : une fin de séance, sans compte
        $this->repository->add(VoiceTrace::record(new DateTimeImmutable('2026-11-15 12:30:45'), null, null, new TracedRequest('SessionEndedRequest', null, null, ['request' => ['type' => 'SessionEndedRequest']]), ['version' => '1.0', 'response' => []], TraceOutcome::SESSION_ENDED, null, 0, null));

        // Then
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM voice_trace');
        $this->assertSame([null, null, null, null, null, null], [$row['user_id'], $row['link_id'], $row['intent'], $row['session_new'], $row['decision'], $row['failure']]);
        $this->assertSame('SESSION_ENDED', $row['outcome']);
    }

    public function testNumbersGrowSoTheNewestExchangeHasTheGreatestNumber(): void
    {
        // When
        $this->repository->add($this->trace(1));
        $this->repository->add($this->trace(2));
        $this->repository->add($this->trace(3));

        // Then
        $this->assertSame([1, 2, 3], array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT duration_ms FROM voice_trace ORDER BY id')));
    }

    public function testBelowTheLimitNothingIsRemoved(): void
    {
        // Given : 999 échanges
        $this->seed(VoiceTrace::RETENTION - 1);

        // When
        $this->repository->add($this->trace(5000));

        // Then : 1 000, aucun n'est parti
        $this->assertSame(1000, $this->rows());
        $this->assertSame(1, $this->scalar('SELECT MIN(duration_ms) FROM voice_trace'));
    }

    public function testTheThousandAndFirstExchangeRemovesTheOldestOne(): void
    {
        // Given : 1 000 échanges, la durée sert de repère (1 pour le plus ancien)
        $this->seed(VoiceTrace::RETENTION);
        $this->assertSame(1000, $this->rows());

        // When
        $this->repository->add($this->trace(5000));

        // Then : toujours 1 000, le plus ancien est parti, le nouveau est le plus récent
        $this->assertSame(1000, $this->rows());
        $this->assertSame(2, $this->scalar('SELECT MIN(duration_ms) FROM voice_trace'));
        $this->assertSame(5000, $this->scalar('SELECT duration_ms FROM voice_trace ORDER BY id DESC LIMIT 1'));
    }

    public function testManyMoreThanTheLimitAreCutBackInOneInsertion(): void
    {
        // Given : 1 200 échanges (la limite a pu être baissée ou la table remplie à la main)
        $this->seed(1200);

        // When
        $this->repository->add($this->trace(5000));

        // Then : les 1 000 plus récents, nouveau compris
        $this->assertSame(1000, $this->rows());
        $this->assertSame(202, $this->scalar('SELECT MIN(duration_ms) FROM voice_trace'));
    }

    public function testAnInsertionCostsTwoStatementsWhateverTheNumberOfRows(): void
    {
        // Given : la table pleine
        $this->seed(VoiceTrace::RETENTION);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->add($this->trace(5000));

        // Then : une insertion et une suppression, pas une de plus
        $this->assertSame(2, $counter->count());
    }

    private function trace(int $durationMs): VoiceTrace
    {
        return VoiceTrace::record(new DateTimeImmutable('2026-11-15 12:00:00'), 'user-1', 'link-1', new TracedRequest('LaunchRequest', null, true, ['request' => ['type' => 'LaunchRequest']]), ['version' => '1.0', 'response' => []], TraceOutcome::ANSWERED, null, $durationMs, null);
    }

    /**
     * `$rows` échanges déjà gardés, numérotés dans l'ordre (la durée vaut 1, 2, 3…), écrits d'un coup.
     */
    private function seed(int $rows): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO voice_trace (created_at, request_type, outcome, duration_ms, request, response) SELECT now(), 'LaunchRequest', 'ANSWERED', n, '{}'::jsonb, '{}'::jsonb FROM generate_series(1, :rows) AS n",
            ['rows' => $rows],
        );
    }

    private function rows(): int
    {
        return $this->scalar('SELECT COUNT(*) FROM voice_trace');
    }

    private function scalar(string $sql): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql);
    }
}
