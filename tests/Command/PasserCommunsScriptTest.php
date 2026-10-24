<?php

namespace Repas\Tests\Command;


use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Le script d'exploitation de la passation (ING-009, `.claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh`), essayé sur une
 * copie (`REPAS_DIR`) avec un faux `docker` et un faux `backup.sh` qui enregistrent leurs appels : ce que le script lance, dans quel ordre, et ce
 * qu'il refuse. Il ne touche à aucune base. Comme ses modèles, `amorcer-ingredients.sh` et `adopter-ingredients.sh`, il ne s'exécute jamais en
 * production depuis un agent de code : c'est une étape humaine.
 */
class PasserCommunsScriptTest extends TestCase
{
    private const string COMMAND = 'compose exec -T php php bin/console repas:ingredients:hand-over-catalog';

    private string $directory;
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 2) . '/.claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh';
        $this->directory = sys_get_temp_dir() . '/ing009-script-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/bin', 0700, true);
        // Le faux docker enregistre chaque appel. Un appel avec --check répond selon FAKE_CHECK : « pending » (le défaut) dit « il reste à faire »
        // (code 1) la première fois puis « rien à faire » (0) ; « done » répond toujours 0. Un appel SANS --apply ni --check (le rapport à
        // blanc) répond FAKE_DRY (0 par défaut ; 1 : « --apply refuserait d'écrire »).
        file_put_contents($this->directory . '/bin/docker', <<<'BASH'
            #!/usr/bin/env bash
            echo "docker $*" >> "$LOG"
            [[ -n ${FAKE_FAIL_ON:-} && "$*" == *"$FAKE_FAIL_ON"* ]] && exit 1
            if [[ "$*" == *" --check"* ]]; then
                n=$(cat "$LOG.checks" 2>/dev/null || echo 0)
                echo $((n + 1)) > "$LOG.checks"
                case ${FAKE_CHECK:-pending} in
                    done) exit 0 ;;
                    *) [[ $n -eq 0 ]] && exit 1 ;;
                esac
            elif [[ "$*" != *" --apply"* ]]; then
                exit "${FAKE_DRY:-0}"
            fi
            exit 0
            BASH);
        file_put_contents($this->directory . '/backup.sh', "#!/usr/bin/env bash\necho \"backup \$*\" >> \"\$LOG\"\n");
        chmod($this->directory . '/bin/docker', 0700);
        chmod($this->directory . '/backup.sh', 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{bin/*,*}', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->directory . '/bin');
        @rmdir($this->directory);
    }

    public function testWithoutAnArgumentItOnlyReadsAndNeverMakesABackupNorWrites(): void
    {
        [$status, $output, $calls] = $this->runScript([]);

        $this->assertSame(0, $status, $output);
        $this->assertSame(['docker ' . self::COMMAND], $calls);
        $this->assertStringContainsString("Lecture seule : rien n'a été modifié", $output);
    }

    public function testWithoutAnArgumentItSaysWhenApplyWouldBeRefusedAndStillExitsCleanly(): void
    {
        [$status, $output, $calls] = $this->runScript([], dry: 1);

        $this->assertSame(0, $status, 'la lecture seule n\'échoue pas : elle dit ce qu\'elle a vu');
        $this->assertSame(['docker ' . self::COMMAND], $calls);
        $this->assertStringContainsString('refuserait d\'écrire (code 1)', $output);
    }

    public function testApplyMakesTheReportThenTheBackupThenTheWriteThenTheCheck(): void
    {
        [$status, $output, $calls] = $this->runScript(['--appliquer', '--accepter-listes-envoyees', '--lancement=essai']);

        $this->assertSame(0, $status, $output);
        $this->assertSame([
            'docker ' . self::COMMAND . ' --accept-sent',
            'docker ' . self::COMMAND . ' --check',
            'backup avant-passation-communs',
            'docker ' . self::COMMAND . ' --apply --accept-sent --source=essai',
            'docker ' . self::COMMAND . ' --check',
        ], $calls);
        $this->assertStringContainsString('passation terminée', $output);
    }

    public function testUntilTheSeedingStopsBeforeTheHandOverEverywhere(): void
    {
        [$status, $output, $calls] = $this->runScript(['--appliquer', '--jusqu-a-l-amorcage', '--accepter-listes-envoyees']);

        $this->assertSame(0, $status, $output);
        $this->assertSame([
            'docker ' . self::COMMAND . ' --stop-before-handover --accept-sent',
            'docker ' . self::COMMAND . ' --check --stop-before-handover',
            'backup avant-passation-communs',
            'docker ' . self::COMMAND . ' --apply --stop-before-handover --accept-sent',
            'docker ' . self::COMMAND . ' --check --stop-before-handover',
        ], $calls);
        $this->assertStringContainsString("La passation n'est PAS faite", $output);
    }

    public function testApplyWithoutOptionsHasNoAcceptSentAndNoRunName(): void
    {
        [$status, , $calls] = $this->runScript(['--appliquer']);

        $this->assertSame(0, $status);
        $this->assertSame([
            'docker ' . self::COMMAND,
            'docker ' . self::COMMAND . ' --check',
            'backup avant-passation-communs',
            'docker ' . self::COMMAND . ' --apply',
            'docker ' . self::COMMAND . ' --check',
        ], $calls);
    }

    public function testWhenTheReportSaysApplyWouldBeRefusedItStopsBeforeTheBackupAndTheWrite(): void
    {
        [$status, $output, $calls] = $this->runScript(['--appliquer'], dry: 1);

        $this->assertSame(1, $status);
        $this->assertSame(['docker ' . self::COMMAND], $calls, 'ni sauvegarde ni écriture');
        $this->assertStringContainsString('Aucune sauvegarde, aucune écriture.', $output);
    }

    public function testWhenThereIsNothingLeftToDoItMakesNeitherABackupNorAWrite(): void
    {
        [$status, $output, $calls] = $this->runScript(['--appliquer'], check: 'done');

        $this->assertSame(0, $status, $output);
        $this->assertSame(['docker ' . self::COMMAND, 'docker ' . self::COMMAND . ' --check'], $calls);
        $this->assertStringContainsString('Rien à faire : aucune sauvegarde, aucune écriture.', $output);
    }

    public function testAnErrorOfTheCommandStopsBeforeTheFinalCheck(): void
    {
        [$status, , $calls] = $this->runScript(['--appliquer'], failOn: '--apply');

        $this->assertNotSame(0, $status);
        $this->assertSame(['docker ' . self::COMMAND, 'docker ' . self::COMMAND . ' --check', 'backup avant-passation-communs', 'docker ' . self::COMMAND . ' --apply'], $calls);
    }

    public function testCheckOnlyReadsAndPassesTheStopOption(): void
    {
        [$status, , $calls] = $this->runScript(['--verifier'], check: 'done');
        $this->assertSame(0, $status);
        $this->assertSame(['docker ' . self::COMMAND . ' --check'], $calls);

        [, , $calls] = $this->runScript(['--verifier', '--jusqu-a-l-amorcage'], check: 'done');
        $this->assertSame(['docker ' . self::COMMAND . ' --check --stop-before-handover'], $calls);
    }

    public function testUnknownOrConflictingArgumentsAreRefusedWithoutAnyCall(): void
    {
        foreach ([['--n-importe-quoi'], ['--appliquer', '--verifier'], ['--compte=abcd1234'], ['--annuler=x']] as $arguments) {
            [$status, $output, $calls] = $this->runScript($arguments);
            $this->assertSame(2, $status, implode(' ', $arguments));
            $this->assertStringContainsString('ECHEC', $output);
            $this->assertSame([], $calls);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<string>} le code de sortie, la sortie, les appels enregistrés
     */
    private function runScript(array $arguments, ?string $failOn = null, string $check = 'pending', int $dry = 0): array
    {
        $log = $this->directory . '/calls.log';
        @unlink($log);
        @unlink($log . '.checks');
        // « bash -s » : le script lit le sien sur l'entrée standard, comme à travers ssh
        $process = new Process(['bash', '-s', '--', ...$arguments], null, [
            'PATH' => $this->directory . '/bin:' . getenv('PATH'),
            'REPAS_DIR' => $this->directory,
            'LOG' => $log,
            'FAKE_FAIL_ON' => $failOn ?? '',
            'FAKE_CHECK' => $check,
            'FAKE_DRY' => (string) $dry,
        ], file_get_contents($this->script));
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput() . $process->getErrorOutput(), is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : []];
    }
}
