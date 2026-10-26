<?php

namespace Repas\Tests\Helper;

use PDO;
use RuntimeException;

/**
 * La base de données de chaque processus de tests PARALLÈLES (`make test-fast`, ParaTest).
 *
 * ParaTest donne à chaque processus un `TEST_TOKEN` (1, 2, 3…). `config/packages/doctrine.yaml` (`when@test`) l'ajoute au nom de la base
 * (`repas_test` devient `repas_test1`, `repas_test2`…) : les processus ne partagent donc aucune table, et DAMA garde sa transaction par test.
 * Cette classe crée la base du processus si elle n'existe pas (le schéma, lui, est créé par `DatabaseTestCase`, comme en série).
 *
 * Sans `TEST_TOKEN` (`make test`, en série), elle ne fait rien.
 *
 * Limite connue : les processus partagent le répertoire temporaire du système (PHP le fixe avant l'amorçage de PHPUnit, un `TMPDIR` posé ici
 * n'y change rien). Aujourd'hui, seul `OpenSslAlexaRequestVerifierTest` y écrit des fichiers `alexa-chain-*` et compte ceux qui restent : tant
 * qu'aucun autre test n'écrit le même préfixe, il n'a pas de voisin.
 */
final class ParallelTestDatabase
{
    /** Verrou consultatif de PostgreSQL : deux `CREATE DATABASE` en même temps échouent (la base modèle est « utilisée »). */
    private const int CREATION_LOCK = 4_719_201;

    public static function prepareProcess(): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        if (!is_string($token) || preg_match('/^\d{1,4}$/', $token) !== 1) {
            return;
        }

        self::createDatabaseIfMissing($token);
    }

    private static function createDatabaseIfMissing(string $token): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            throw new RuntimeException('DATABASE_URL est absente ou illisible : impossible de préparer la base du processus de tests.');
        }

        // Le nom que Doctrine utilisera : celui de l'adresse, `_test`, puis le jeton (doctrine.yaml, `when@test`)
        $name = ltrim($parts['path'], '/') . '_test' . $token;
        if (preg_match('/^[a-z0-9_]{1,63}$/', $name) !== 1) {
            throw new RuntimeException("Nom de base de tests inattendu : $name");
        }

        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=postgres', $parts['host'], $parts['port'] ?? 5432),
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->prepare('SELECT pg_advisory_lock(?)')->execute([self::CREATION_LOCK]);
        try {
            $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $exists->execute([$name]);
            if ($exists->fetchColumn() === false) {
                $pdo->exec('CREATE DATABASE "' . $name . '"');
            }
        } finally {
            $pdo->prepare('SELECT pg_advisory_unlock(?)')->execute([self::CREATION_LOCK]);
        }
    }
}
