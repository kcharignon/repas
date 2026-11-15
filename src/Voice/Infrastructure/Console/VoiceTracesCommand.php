<?php

namespace Repas\Voice\Infrastructure\Console;


use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Clock\ClockInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Voice\Application\Loader\VoiceTraceLoader;
use Repas\Voice\Application\Query\GetVoiceTrace\GetVoiceTraceQuery;
use Repas\Voice\Application\Query\GetVoiceTrace\VoiceTraceDetailDTO;
use Repas\Voice\Application\Query\ListVoiceTraces\ListVoiceTracesQuery;
use Repas\Voice\Application\Query\ListVoiceTraces\VoiceTraceSummaryDTO;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Domain\Model\VoiceTrace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La trace des échanges avec Alexa, en LECTURE SEULE (ADR 0029 décision 14, phase de test) : ce qu'Amazon a envoyé (la requête assainie : intention,
 * créneaux entendus et leur résolution d'entité, état du dialogue), ce que Repas a répondu, l'issue, la durée et la panne éventuelle. C'est ce qui permet de
 * retrouver pourquoi une phrase n'a pas été comprise (« divers » ne reconnaît pas le rayon `divers` ?) sans journaliser la voix.
 *
 *     php bin/console repas:voice:traces                      les 20 derniers échanges, une ligne chacun
 *     php bin/console repas:voice:traces --last=50 --failed   les pannes parmi les 50 derniers
 *     php bin/console repas:voice:traces --since=2h           ce qui date de moins de deux heures
 *     php bin/console repas:voice:traces --id=412             un échange en entier, requête et réponse en JSON
 *
 * Elle lit ce qui existe, interrupteur `ALEXA_TRACE_ENABLED` allumé ou non, et n'écrit jamais. Elle ne sort aucun secret : la table n'en garde pas (le jeton
 * d'accès et les identifiants d'utilisateur ou d'appareil Alexa n'y entrent jamais) ; le compte est désigné par une référence courte (SHA-256), pas par
 * son identifiant. Ce que l'utilisateur a dit y figure, c'est son but : elle ne se lance que sur le serveur, par le propriétaire.
 */
#[AsCommand(
    name: 'repas:voice:traces',
    description: "Les derniers échanges avec Alexa (lecture seule) : ce qu'Amazon a envoyé, ce que Repas a répondu, les pannes",
)]
final class VoiceTracesCommand extends Command
{
    /** Combien d'échanges quand `--last` n'est pas donné. */
    private const int DEFAULT_LAST = 20;

    /** Le fuseau où les dates s'affichent et où une date de `--since` se lit. */
    private const string TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('last', null, InputOption::VALUE_REQUIRED, sprintf('Les N derniers échanges (%d par défaut, %d au plus)', self::DEFAULT_LAST, VoiceTraceLoader::MAX_LIMIT), (string) self::DEFAULT_LAST)
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Un échange en entier (requête assainie et réponse en JSON), d\'après son numéro')
            ->addOption('intent', null, InputOption::VALUE_REQUIRED, 'Seulement cette intention (nom exact, par exemple AddItemIntent)')
            ->addOption('failed', null, InputOption::VALUE_NONE, 'Seulement les pannes')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Seulement depuis : une durée (30m, 2h, 1d) ou une date (2026-11-15, 2026-11-15 14:30, heure de Paris)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = $input->getOption('id');
        if ($id !== null) {
            return $this->showOne($io, $output, $id);
        }

        $last = filter_var($input->getOption('last'), FILTER_VALIDATE_INT);
        if ($last === false || $last < 1) {
            $io->error('--last doit être un nombre entier d\'au moins 1.');

            return Command::INVALID;
        }

        $since = null;
        $sinceOption = $input->getOption('since');
        if ($sinceOption !== null) {
            $since = $this->since((string) $sinceOption);
            if ($since === null) {
                $io->error('--since doit être une durée (30m, 2h, 1d) ou une date (2026-11-15 ou 2026-11-15 14:30).');

                return Command::INVALID;
            }
        }

        $intent = $input->getOption('intent');
        $traces = $this->queryBus->ask(new ListVoiceTracesQuery(
            min($last, VoiceTraceLoader::MAX_LIMIT),
            $intent === null || $intent === '' ? null : (string) $intent,
            (bool) $input->getOption('failed'),
            $since,
        ));
        \assert(is_array($traces));

        $io->title('Échanges avec Alexa (le plus récent d\'abord, lecture seule)');
        if ($last > VoiceTraceLoader::MAX_LIMIT) {
            $io->text(sprintf('--last est limité à %d.', VoiceTraceLoader::MAX_LIMIT));
        }
        if ($traces === []) {
            $io->text('Aucun échange gardé pour ces critères. (La trace est coupée tant que ALEXA_TRACE_ENABLED n\'est pas allumé, et ne garde que les 1 000 derniers.)');

            return Command::SUCCESS;
        }

        foreach ($traces as $trace) {
            \assert($trace instanceof VoiceTraceSummaryDTO);
            $output->writeln($this->line($trace), OutputInterface::OUTPUT_RAW);
        }
        $io->newLine();
        $io->text(sprintf('%d échange(s). Le détail d\'un échange : --id=N.', count($traces)));

        return Command::SUCCESS;
    }

    private function showOne(SymfonyStyle $io, OutputInterface $output, mixed $idOption): int
    {
        $id = filter_var($idOption, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $io->error('--id doit être le numéro d\'un échange (un nombre entier).');

            return Command::INVALID;
        }

        $detail = $this->queryBus->ask(new GetVoiceTraceQuery($id));
        if (!$detail instanceof VoiceTraceDetailDTO) {
            $io->error(sprintf('Aucun échange n°%d (seuls les %d plus récents sont gardés).', $id, VoiceTrace::RETENTION));

            return Command::FAILURE;
        }

        $summary = $detail->summary;
        $lines = [
            sprintf('Échange n°%d', $summary->id),
            '    date          : ' . $this->date($summary->createdAt),
            '    type          : ' . $summary->requestType . ($summary->sessionNew === null ? '' : ($summary->sessionNew ? ' (séance neuve)' : ' (séance ouverte)')),
            '    intention     : ' . ($summary->intent ?? '(aucune)'),
            '    issue         : ' . $this->outcomeLabel($summary->outcome) . ' (' . $summary->outcome . ')',
            '    décision      : ' . ($summary->decision ?? '(aucune)'),
            '    durée         : ' . $summary->durationMs . ' ms',
            '    compte        : ' . ($detail->userId === null ? '(aucun : jeton non résolu)' : '[' . substr(hash('sha256', $detail->userId), 0, 8) . ']'),
            '    liaison       : ' . ($detail->linkId === null ? '(aucune)' : '[' . substr(hash('sha256', $detail->linkId), 0, 8) . ']'),
            '',
            'Requête assainie (ce qu\'Amazon a envoyé, sans jeton ni identifiant) :',
            $this->json($detail->request),
            '',
            'Réponse rendue à Alexa :',
            $this->json($detail->response),
        ];
        if ($detail->failure !== null) {
            $lines[] = '';
            $lines[] = 'Panne :';
            $lines[] = $this->json($detail->failure);
        }

        $output->writeln($lines, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function line(VoiceTraceSummaryDTO $trace): string
    {
        $line = sprintf('#%d  %s  %s  [%s]', $trace->id, $this->date($trace->createdAt), $trace->intent ?? $trace->requestType, $this->outcomeLabel($trace->outcome));
        if ($trace->dialogueStep !== null) {
            $line .= sprintf('  (question en cours : %s)', $trace->dialogueStep);
        }
        if ($trace->heard !== null) {
            $line .= '  entendu : ' . $trace->heard;
        }
        $line .= $trace->said === null ? '  Alexa : (silence)' : sprintf('  Alexa : « %s »', $trace->said);
        if ($trace->failureClass !== null) {
            $line .= '  panne : ' . $trace->failureClass;
        }

        return $line . sprintf('  (%s%d ms)', $trace->decision === null ? '' : $trace->decision . ', ', $trace->durationMs);
    }

    private function outcomeLabel(string $outcome): string
    {
        return TraceOutcome::tryFrom($outcome)?->label() ?? $outcome;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone(self::TIMEZONE))->format('d/m/Y H:i:s');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Une durée relative (`30m`, `2h`, `1d` ou `1j`) ou une date (lue à l'heure de Paris) ; `null` quand ce n'est ni l'une ni l'autre.
     */
    private function since(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,6})\s*([mhdj])$/i', $value, $matches) === 1) {
            $unit = match (strtolower($matches[2])) {
                'm' => 'minutes',
                'h' => 'hours',
                default => 'days',
            };

            return DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('-%d %s', (int) $matches[1], $unit));
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone(self::TIMEZONE));
        } catch (Exception) {
            return null;
        }
    }
}
