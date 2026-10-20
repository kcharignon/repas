<?php

namespace Repas\Tests\Command;


use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Le script d'exploitation de la migration d'adoption (ING-003, `.claude/skills/repas-ops/references/adopter-ingredients.sh`), essayé sur
 * une copie (`REPAS_DIR`) avec un faux `docker` et un faux `backup.sh` qui enregistrent leurs appels : ce que le script lance, dans quel
 * ordre, et ce qu'il refuse. Il ne touche à aucune base. Comme son modèle, `rouvrir-listes-envoyees.sh`, il ne s'exécute jamais en
 * production depuis un agent de code : c'est une étape humaine.
 */
class AdoptIngredientsScriptTest extends TestCase
{
    private const string COMMAND = 'compose exec -T php php bin/console repas:ingredients:adopt-commons';

    private string $directory;
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 2) . '/.claude/skills/repas-ops/references/adopter-ingredients.sh';
        $this->directory = sys_get_temp_dir() . '/ing003-script-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/bin', 0700, true);
        // Le faux docker enregistre chaque appel. Un appel avec --check dit « il reste à faire » (code 1) la première fois quand FAKE_PENDING=1
        // (le défaut), puis « rien à faire » (code 0) : le script décide d'après ce code de sortie s'il y a quelque chose à écrire.
        file_put_contents($this->directory . '/bin/docker', <<<'BASH'
            #!/usr/bin/env bash
            echo "docker $*" >> "$LOG"
            [[ -n ${FAKE_FAIL_ON:-} && "$*" == *"$FAKE_FAIL_ON"* ]] && exit 1
            if [[ "$*" == *" --check"* ]]; then
                n=$(cat "$LOG.checks" 2>/dev/null || echo 0)
                echo $((n + 1)) > "$LOG.checks"
                [[ ${FAKE_PENDING:-1} == 1 && $n -eq 0 ]] && exit 1
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

    public function testApplyMakesTheReportThenTheBackupThenTheWriteThenTheCheck(): void
    {
        [$status, $output, $calls] = $this->runScript(['--appliquer', '--accepter-listes-envoyees', '--compte=abcd1234', '--sauf=ef567890', '--lancement=essai']);

        $this->assertSame(0, $status, $output);
        $this->assertSame([
            'docker ' . self::COMMAND . ' --user=abcd1234 --except=ef567890',
            'docker ' . self::COMMAND . ' --check --user=abcd1234 --except=ef567890',
            'backup avant-adoption-ingredients',
            'docker ' . self::COMMAND . ' --apply --accept-sent --source=essai --user=abcd1234 --except=ef567890',
            'docker ' . self::COMMAND . ' --check --user=abcd1234 --except=ef567890',
        ], $calls);
        $this->assertStringContainsString('migration terminée', $output);
    }

    public function testApplyWithoutOptionsHasNoAcceptSentAndNoRunName(): void
    {
        [$status, , $calls] = $this->runScript(['--appliquer']);

        $this->assertSame(0, $status);
        $this->assertSame([
            'docker ' . self::COMMAND,
            'docker ' . self::COMMAND . ' --check',
            'backup avant-adoption-ingredients',
            'docker ' . self::COMMAND . ' --apply',
            'docker ' . self::COMMAND . ' --check',
        ], $calls);
    }

    public function testWhenThereIsNothingLeftToDoItMakesNeitherABackupNorAWrite(): void
    {
        [$status, $output, $calls] = $this->runScript(['--appliquer'], pending: false);

        $this->assertSame(0, $status, $output);
        $this->assertSame(['docker ' . self::COMMAND, 'docker ' . self::COMMAND . ' --check'], $calls);
        $this->assertStringContainsString('Rien à faire : aucune sauvegarde, aucune écriture.', $output);
    }

    public function testABlockingAnomalyInTheReportStopsBeforeTheBackupAndTheWrite(): void
    {
        [$status, , $calls] = $this->runScript(['--appliquer'], failOn: 'adopt-commons');

        $this->assertNotSame(0, $status);
        $this->assertSame(['docker ' . self::COMMAND], $calls, 'ni sauvegarde ni écriture');
    }

    public function testAFailedWriteStopsBeforeTheFinalCheck(): void
    {
        [$status, , $calls] = $this->runScript(['--appliquer'], failOn: '--apply');

        $this->assertNotSame(0, $status);
        $this->assertSame(['docker ' . self::COMMAND, 'docker ' . self::COMMAND . ' --check', 'backup avant-adoption-ingredients', 'docker ' . self::COMMAND . ' --apply'], $calls);
    }

    public function testCheckOnlyReads(): void
    {
        [$status, , $calls] = $this->runScript(['--verifier'], pending: false);

        $this->assertSame(0, $status);
        $this->assertSame(['docker ' . self::COMMAND . ' --check'], $calls);
    }

    public function testUndoNeedsApplyAndMakesABackupFirst(): void
    {
        [$refused, $output, $calls] = $this->runScript(['--annuler=migration-2026-10-21']);
        $this->assertSame(2, $refused);
        $this->assertStringContainsString('ajoute --appliquer', $output);
        $this->assertSame([], $calls);

        [$status, , $calls] = $this->runScript(['--appliquer', '--annuler=migration-2026-10-21', '--accepter-listes-envoyees']);
        $this->assertSame(0, $status);
        $this->assertSame(['backup avant-annulation-adoption-ingredients', 'docker ' . self::COMMAND . ' --apply --undo=migration-2026-10-21 --accept-sent'], $calls);
    }

    public function testUnknownOrConflictingArgumentsAreRefusedWithoutAnyCall(): void
    {
        foreach ([['--n-importe-quoi'], ['--appliquer', '--verifier'], ['--compte=']] as $arguments) {
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
    private function runScript(array $arguments, ?string $failOn = null, bool $pending = true): array
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
            'FAKE_PENDING' => $pending ? '1' : '0',
        ], file_get_contents($this->script));
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput() . $process->getErrorOutput(), is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : []];
    }
}
