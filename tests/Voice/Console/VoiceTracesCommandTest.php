<?php

namespace Repas\Tests\Voice\Console;


use DateTimeImmutable;
use DateTimeZone;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Voice\Domain\Interface\VoiceTraceRepository;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\TracedFailure;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Domain\Service\AlexaTraceSanitizer;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `repas:voice:traces` de bout en bout (ADR 0029 décision 14) : la commande de console, le vrai bus, la vraie base. Lecture seule : une ligne par échange, le détail
 * complet avec `--id`, des filtres, et aucun texte interprété comme du formatage.
 */
class VoiceTracesCommandTest extends DatabaseTestCase
{
    private CommandTester $tester;
    private VoiceTraceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:voice:traces'));
        $this->repository = static::getContainer()->get(VoiceTraceRepository::class);
    }

    public function testWithoutAnyExchangeItSaysSoAndSucceeds(): void
    {
        // When
        $exit = $this->tester->execute([]);

        // Then
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Aucun échange gardé pour ces critères.', $this->tester->getDisplay());
    }

    public function testEachExchangeIsOneLineWithTheIntentTheOutcomeWhatWasHeardAndWhatAlexaSaid(): void
    {
        // Given : « divers » dit à la question du rayon, qu'Amazon n'a pas reconnu ; puis un ajout réussi
        $this->save(AlexaTestRequest::answerDepartment('divers', null)->withSessionAttributes(['dialogue' => ['step' => 'department', 'phrase' => 'ciboulette', 'options' => [], 'name' => 'ciboulette', 'misses' => 0]]), 'Dans quel rayon ?', decision: 'AnswerSpokenDialogue:department', durationMs: 31);
        $this->save(AlexaTestRequest::addItem('du pain'), "C'est noté : pain.", decision: 'AddSpokenItem', durationMs: 18);

        // When
        $exit = $this->tester->execute([]);
        $lines = array_values(array_filter(explode("\n", $this->tester->getDisplay()), static fn(string $line): bool => str_starts_with($line, '#')));

        // Then : le plus récent d'abord
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertCount(2, $lines);
        $this->assertMatchesRegularExpression('/^#\d+  15\/11\/2026 12:00:00  AddItemIntent  \[répondu\]  entendu : item="du pain"  Alexa : « C\'est noté : pain\. »  \(AddSpokenItem, 18 ms\)$/', $lines[0]);
        $this->assertMatchesRegularExpression('/^#\d+  15\/11\/2026 12:00:00  AnswerDepartmentIntent  \[répondu\]  \(question en cours : department\)  entendu : department="divers" \(non reconnu\)  Alexa : « Dans quel rayon \? »  \(AnswerSpokenDialogue:department, 31 ms\)$/', $lines[1]);
        $this->assertStringContainsString('2 échange(s). Le détail d\'un échange : --id=N.', $this->tester->getDisplay());
    }

    public function testAFailureLineNamesTheExceptionClass(): void
    {
        // Given
        $this->save(AlexaTestRequest::addItem('du pain'), "Je n'ai pas pu faire ça. Réessaie dans un instant.", TraceOutcome::FAILED, new RuntimeException('panne'), 'AddSpokenItem');

        // When
        $this->tester->execute(['--failed' => true]);

        // Then
        $this->assertStringContainsString('[PANNE]', $this->tester->getDisplay());
        $this->assertStringContainsString('panne : RuntimeException', $this->tester->getDisplay());
    }

    public function testTheFiltersNarrowTheList(): void
    {
        // Given
        $this->save(AlexaTestRequest::addItem('du pain'), 'Noté.', decision: 'AddSpokenItem');
        $this->save(AlexaTestRequest::removeItem('du lait'), 'Retiré.', decision: 'RemoveSpokenItem');
        $this->save(AlexaTestRequest::addItem('du beurre'), "Je n'ai pas pu faire ça.", TraceOutcome::FAILED, new RuntimeException('panne'), 'AddSpokenItem');

        // When / Then : par intention
        $this->tester->execute(['--intent' => 'RemoveItemIntent']);
        $this->assertStringContainsString('1 échange(s)', $this->tester->getDisplay());
        $this->assertStringContainsString('du lait', $this->tester->getDisplay());
        $this->assertStringNotContainsString('du pain', $this->tester->getDisplay());

        // When / Then : pannes seulement
        $this->tester->execute(['--failed' => true]);
        $this->assertStringContainsString('1 échange(s)', $this->tester->getDisplay());
        $this->assertStringContainsString('du beurre', $this->tester->getDisplay());

        // When / Then : les deux derniers seulement
        $this->tester->execute(['--last' => '2']);
        $this->assertStringContainsString('2 échange(s)', $this->tester->getDisplay());
        $this->assertStringNotContainsString('du pain', $this->tester->getDisplay());
    }

    public function testSinceTakesARelativeDurationOrADate(): void
    {
        // Given : un échange d'il y a 30 minutes, un d'il y a 5 heures, un d'hier
        $this->save(AlexaTestRequest::addItem('récent'), 'Noté.', at: new DateTimeImmutable('-30 minutes'));
        $this->save(AlexaTestRequest::addItem('midi'), 'Noté.', at: new DateTimeImmutable('-5 hours'));
        $this->save(AlexaTestRequest::addItem('hier'), 'Noté.', at: new DateTimeImmutable('-30 hours'));

        // When / Then : une durée
        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--since' => '2h']));
        $this->assertStringContainsString('1 échange(s)', $this->tester->getDisplay());
        $this->assertStringContainsString('récent', $this->tester->getDisplay());
        $this->tester->execute(['--since' => '1d']);
        $this->assertStringContainsString('2 échange(s)', $this->tester->getDisplay());
        $this->tester->execute(['--since' => '90m']);
        $this->assertStringContainsString('1 échange(s)', $this->tester->getDisplay());

        // When / Then : une date, lue à l'heure de Paris (demain : rien)
        $tomorrow = new DateTimeImmutable('+1 day', new DateTimeZone('Europe/Paris'));
        $this->tester->execute(['--since' => $tomorrow->format('Y-m-d')]);
        $this->assertStringContainsString('Aucun échange gardé', $this->tester->getDisplay());
        $this->tester->execute(['--since' => '2020-01-01']);
        $this->assertStringContainsString('3 échange(s)', $this->tester->getDisplay());
    }

    public function testAnInvalidOptionIsRefusedWithAMessageAndNothingIsRead(): void
    {
        foreach ([['--last' => '0'], ['--last' => 'beaucoup'], ['--since' => 'zzz'], ['--since' => ''], ['--id' => 'abc'], ['--id' => '0']] as $options) {
            // When
            $exit = $this->tester->execute($options);

            // Then
            $this->assertSame(Command::INVALID, $exit, json_encode($options));
        }
        $this->assertStringContainsString('--id doit être le numéro d\'un échange', $this->tester->getDisplay());
    }

    public function testMoreThanTheLimitIsCutAtTwoHundredAndSaysSo(): void
    {
        // Given
        $this->save(AlexaTestRequest::launch(), 'Salut.');

        // When
        $exit = $this->tester->execute(['--last' => '500']);

        // Then
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('--last est limité à 200.', $this->tester->getDisplay());
        $this->assertStringContainsString('1 échange(s)', $this->tester->getDisplay());
    }

    public function testOneExchangeIsShownInFullWithItsRequestItsAnswerAndItsFailure(): void
    {
        // Given : une panne, le compte et la liaison ; la phrase dite contient des balises que la console ne doit pas interpréter
        $this->save(
            AlexaTestRequest::answerDepartment('<info>divers</info>', null)->withAccessToken('FACTICE-JETON-A-NE-JAMAIS-AFFICHER'),
            "Je n'ai pas pu faire ça.",
            TraceOutcome::FAILED,
            new RuntimeException('échec <error>de</error> test'),
            'AnswerSpokenDialogue:department',
            userId: 'user-1',
            linkId: 'link-1',
        );
        $id = (int) $this->entityManager->getConnection()->fetchOne('SELECT id FROM voice_trace');

        // When
        $exit = $this->tester->execute(['--id' => (string) $id]);
        $display = $this->tester->getDisplay();

        // Then : le résumé, la requête assainie et la réponse en JSON, la panne ; le compte par une référence courte, jamais son identifiant ni le jeton
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString("Échange n°$id", $display);
        $this->assertStringContainsString('issue         : PANNE (FAILED)', $display);
        $this->assertStringContainsString('décision      : AnswerSpokenDialogue:department', $display);
        $this->assertStringContainsString('compte        : [' . substr(hash('sha256', 'user-1'), 0, 8) . ']', $display);
        $this->assertStringNotContainsString('user-1', $display);
        $this->assertStringContainsString('"value": "<info>divers</info>"', $display);
        $this->assertStringContainsString('"code": "ER_SUCCESS_NO_MATCH"', $display);
        $this->assertStringContainsString('"text": "Je n\'ai pas pu faire ça."', $display);
        $this->assertStringContainsString('"class": "RuntimeException"', $display);
        $this->assertStringContainsString('échec <error>de</error> test', $display);
        $this->assertStringNotContainsString('FACTICE-JETON-A-NE-JAMAIS-AFFICHER', $display);
    }

    public function testAnUnknownExchangeIsAFailureWithAMessage(): void
    {
        // When
        $exit = $this->tester->execute(['--id' => '987654321']);

        // Then
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Aucun échange n°987654321 (seuls les 1000 plus récents sont gardés).', $this->tester->getDisplay());
    }

    public function testTheCommandNeverWrites(): void
    {
        // Given
        $this->save(AlexaTestRequest::launch(), 'Salut.');
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM voice_trace ORDER BY id');

        // When
        $this->tester->execute([]);
        $this->tester->execute(['--id' => (string) $before[0]['id']]);

        // Then
        $this->assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM voice_trace ORDER BY id'));
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
        $sanitizer = new AlexaTraceSanitizer();
        $this->repository->add(VoiceTrace::record(
            $at ?? new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('Europe/Paris')),
            $userId,
            $linkId,
            $sanitizer->request(json_decode($request->toJson(), true)),
            $sanitizer->response(($said === null ? AlexaResponse::silence() : AlexaResponse::say($said))->toArray()),
            $outcome,
            $decision,
            $durationMs,
            $failure === null ? null : TracedFailure::fromThrowable($failure),
        ));
    }
}
