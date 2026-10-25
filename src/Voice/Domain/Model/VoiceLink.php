<?php

namespace Repas\Voice\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * La liaison d'un compte Repas avec un compte Alexa (ADR 0029 décision 6). Elle naît au premier échange de jetons réussi ; son
 * identifiant d'utilisateur Alexa n'est connu qu'à la première requête d'Alexa (VOX-204) et ne sert qu'à reconnaître une liaison
 * existante (décision 10). Retirée par l'utilisateur ou par un rejeu de jeton, elle est révoquée : ses jetons ne servent plus et son
 * identifiant d'utilisateur Alexa est effacé.
 */
final class VoiceLink implements ModelInterface
{
    use ModelTrait;

    /** Le seul fournisseur de commande vocale pour l'instant : le contrat d'API le dit, pour qu'un autre puisse s'ajouter. */
    public const string PROVIDER = 'alexa';

    /**
     * Écart minimal, en secondes, entre deux enregistrements de la date de dernier usage d'une même liaison (voir `isUseRecordDue()`) :
     * une séance parlée envoie plusieurs requêtes, elles n'ont pas besoin d'une écriture chacune. Même valeur que
     * `User::LOGIN_RECORD_INTERVAL_SECONDS`.
     */
    public const int USE_RECORD_INTERVAL_SECONDS = 600;

    private function __construct(
        private string $id,
        private string $userId,
        private ?string $alexaUserId,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $lastUsedAt,
        private ?DateTimeImmutable $revokedAt,
    ) {
    }

    public static function create(string $id, string $userId, DateTimeImmutable $now): self
    {
        return new self($id, $userId, null, $now, null, null);
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            userId: $datas['user_id'],
            alexaUserId: $datas['alexa_user_id'],
            createdAt: $datas['created_at'],
            lastUsedAt: $datas['last_used_at'],
            revokedAt: $datas['revoked_at'],
        );
    }

    /**
     * Faut-il écrire la date de dernier usage à l'instant `$now` ? Oui si elle est inconnue ou date d'au moins
     * `USE_RECORD_INTERVAL_SECONDS`. Se juge sur la valeur DÉJÀ lue en résolvant le jeton (`ResolvedAccessGrantDTO`), sans requête : c'est
     * ce qui rend la plupart des requêtes d'Alexa gratuites (comme `User::shouldRecordLogin()`).
     */
    public static function isUseRecordDue(?DateTimeImmutable $lastUsedAt, DateTimeImmutable $now): bool
    {
        return $lastUsedAt === null || $now->getTimestamp() - $lastUsedAt->getTimestamp() >= self::USE_RECORD_INTERVAL_SECONDS;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAlexaUserId(): ?string
    {
        return $this->alexaUserId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * Retire la liaison. Idempotent : une liaison déjà révoquée garde la date de sa première révocation. L'identifiant d'utilisateur Alexa est
     * effacé : il ne sert qu'à reconnaître une liaison existante (ADR 0029 décision 10), une liaison révoquée n'existe plus.
     */
    public function revoke(DateTimeImmutable $now): self
    {
        $this->revokedAt ??= $now;
        $this->alexaUserId = null;

        return $this;
    }
}
