<?php

namespace Repas\Tests\Command;


use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Le script qui allume ou coupe l'amorçage automatique (ING-008, `.claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh`), essayé sur
 * une copie (`REPAS_DIR`) avec un faux `docker` : ce qu'il écrit dans le `.env` (jamais de doublon, copie de l'ancien fichier, droits 600), ce
 * qu'il lance, et ce qu'il refuse. Même garde-fous que `ecrire-drive-enabled.sh` et `ecrire-app-version.sh`.
 */
class EcrireIngredientSeedingScriptTest extends TestCase
{
    private string $directory;
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 2) . '/.claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh';
        $this->directory = sys_get_temp_dir() . '/ing008-switch-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/bin', 0700, true);
        // Le faux docker enregistre chaque appel ; « printenv » répond la valeur que le conteneur verrait (FAKE_SEEN, par défaut celle du .env)
        file_put_contents($this->directory . '/bin/docker', <<<'BASH'
            #!/usr/bin/env bash
            echo "docker $*" >> "$LOG"
            if [[ "$*" == *printenv* ]]; then
                if [[ -n ${FAKE_SEEN+x} ]]; then echo "$FAKE_SEEN"; else sed -n 's/^INGREDIENT_SEEDING_ENABLED=//p' "$REPAS_DIR/.env" | tail -n 1; fi
            fi
            [[ "$*" == *wget* && -n ${FAKE_NO_HEALTH:-} ]] && exit 1
            exit 0
            BASH);
        chmod($this->directory . '/bin/docker', 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{bin/*,.env*,*}', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->directory . '/bin');
        @rmdir($this->directory);
    }

    public function testItTurnsTheSwitchOnWritesOneLineKeepsACopyAndChecksTheContainer(): void
    {
        // Given : un .env sans la ligne
        file_put_contents($this->directory . '/.env', "APP_HOST=example.org\nDRIVE_ENABLED=true\n");
        chmod($this->directory . '/.env', 0644);

        // When
        [$status, $output, $calls] = $this->runScript(['true']);

        // Then
        $this->assertSame(0, $status, $output);
        $this->assertSame("APP_HOST=example.org\nDRIVE_ENABLED=true\nINGREDIENT_SEEDING_ENABLED=true\n", file_get_contents($this->directory . '/.env'));
        $this->assertSame("APP_HOST=example.org\nDRIVE_ENABLED=true\n", file_get_contents($this->directory . '/.env.avant-amorcage'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->directory . '/.env')), -4));
        $this->assertStringContainsString('Avant : INGREDIENT_SEEDING_ENABLED=(absente, donc false)', $output);
        $this->assertStringContainsString('OK : INGREDIENT_SEEDING_ENABLED=true, le conteneur php le voit et /api/v1/health répond.', $output);
        $this->assertSame([
            'docker compose up -d --wait --wait-timeout 240 php',
            'docker compose exec -T php printenv INGREDIENT_SEEDING_ENABLED',
            'docker compose exec -T edge wget -q -O - http://php/api/v1/health',
        ], $calls);
    }

    public function testItReplacesTheExistingLineWithoutDuplicatingIt(): void
    {
        // Given : deux lignes (un fichier abîmé) dont la dernière dit true
        file_put_contents($this->directory . '/.env', "INGREDIENT_SEEDING_ENABLED=false\nAPP_HOST=example.org\nINGREDIENT_SEEDING_ENABLED=true\n");

        // When : on coupe
        [$status, $output] = $this->runScript(['false']);

        // Then : une seule ligne, la nouvelle, à la fin
        $this->assertSame(0, $status, $output);
        $this->assertSame("APP_HOST=example.org\nINGREDIENT_SEEDING_ENABLED=false\n", file_get_contents($this->directory . '/.env'));
        $this->assertStringContainsString('Avant : INGREDIENT_SEEDING_ENABLED=true', $output);
    }

    public function testAnAlreadyUpToDateFileIsNotRewrittenButTheContainerIsStillChecked(): void
    {
        // Given
        file_put_contents($this->directory . '/.env', "INGREDIENT_SEEDING_ENABLED=true\n");

        // When
        [$status, $output, $calls] = $this->runScript(['true']);

        // Then
        $this->assertSame(0, $status, $output);
        $this->assertFileDoesNotExist($this->directory . '/.env.avant-amorcage');
        $this->assertStringContainsString('Le fichier est déjà à jour ; on vérifie quand même le conteneur.', $output);
        $this->assertContains('docker compose exec -T php printenv INGREDIENT_SEEDING_ENABLED', $calls);
    }

    public function testAMissingLineIsTreatedAsOffAndCuttingItWritesTheLineAnyway(): void
    {
        // Given : aucune ligne (donc coupé) ; on demande false
        file_put_contents($this->directory . '/.env', "APP_HOST=example.org\n");

        // When
        [$status, $output] = $this->runScript(['false']);

        // Then : la ligne est écrite explicitement (le .env ne dépend plus du défaut)
        $this->assertSame(0, $status, $output);
        $this->assertSame("APP_HOST=example.org\nINGREDIENT_SEEDING_ENABLED=false\n", file_get_contents($this->directory . '/.env'));
    }

    public function testItFailsWhenTheContainerDoesNotSeeTheValue(): void
    {
        // Given
        file_put_contents($this->directory . '/.env', "APP_HOST=example.org\n");

        // When
        [$status, $output, $calls] = $this->runScript(['true'], seen: 'false');

        // Then
        $this->assertSame(1, $status);
        $this->assertStringContainsString('ECHEC : le conteneur php voit INGREDIENT_SEEDING_ENABLED=false au lieu de true.', $output);
        $this->assertNotContains('docker compose exec -T edge wget -q -O - http://php/api/v1/health', $calls);
    }

    public function testItRefusesAnythingButTrueOrFalseAndAMissingEnvFileWithoutAnyCall(): void
    {
        foreach ([[], ['oui'], ['TRUE'], ['1']] as $arguments) {
            [$status, $output, $calls] = $this->runScript($arguments);
            $this->assertSame(2, $status, implode(' ', $arguments));
            $this->assertStringContainsString('ECHEC : donner true (allumer) ou false (couper)', $output);
            $this->assertSame([], $calls);
        }

        // Sans .env
        [$status, $output, $calls] = $this->runScript(['true']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('introuvable', $output);
        $this->assertSame([], $calls);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<string>} le code de sortie, la sortie, les appels enregistrés
     */
    private function runScript(array $arguments, ?string $seen = null): array
    {
        $log = $this->directory . '/calls.log';
        @unlink($log);
        $environment = [
            'PATH' => $this->directory . '/bin:' . getenv('PATH'),
            'REPAS_DIR' => $this->directory,
            'LOG' => $log,
        ];
        if ($seen !== null) {
            $environment['FAKE_SEEN'] = $seen;
        }
        $process = new Process(['bash', '-s', '--', ...$arguments], null, $environment, file_get_contents($this->script));
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput() . $process->getErrorOutput(), is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : []];
    }
}
