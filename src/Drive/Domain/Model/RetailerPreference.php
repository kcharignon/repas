<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Ce que Repas retient du choix d'enseigne d'un utilisateur (DRV-703, ADR 0027 décision 4) : son **drive habituel** (facultatif, choisi dans
 * « Moi » : « ne me demande plus ») et la **dernière enseigne utilisée** (posée quand il ouvre le drive). Une seule par utilisateur, toutes
 * enseignes confondues ; agrégat distinct de `DriveSettings` (qui est par enseigne) et de `User` (ADR 0020).
 *
 * Elle ne référence une enseigne que par son identifiant (ADR 0013) et ne dit jamais qu'une enseigne est « configurée » : c'est la présence
 * d'un `DriveSettings`. Une enseigne éteinte (`DRIVE_RETAILERS`) n'efface pas la préférence : la sélection l'ignore tant qu'elle est éteinte.
 */
final class RetailerPreference implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $userId,
        private ?Retailer $defaultRetailer,
        private ?Retailer $lastUsedRetailer,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Une préférence vide : ni drive habituel ni dernière enseigne.
     */
    public static function create(string $userId, DateTimeImmutable $now): self
    {
        return new self($userId, null, null, $now);
    }

    /**
     * @param array{user_id: string, default_retailer: ?Retailer, last_used_retailer: ?Retailer, updated_at: DateTimeImmutable} $datas
     */
    public static function load(array $datas): self
    {
        return new self($datas['user_id'], $datas['default_retailer'], $datas['last_used_retailer'], $datas['updated_at']);
    }

    /**
     * L'identifiant est celui de l'utilisateur : il n'a qu'une préférence.
     */
    public function getId(): string
    {
        return $this->userId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getDefaultRetailer(): ?Retailer
    {
        return $this->defaultRetailer;
    }

    public function getLastUsedRetailer(): ?Retailer
    {
        return $this->lastUsedRetailer;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Fixe (ou, avec `null`, efface) le drive habituel. Sans changement, rien n'est touché (pas même la date).
     */
    public function chooseDefault(?Retailer $retailer, DateTimeImmutable $now): self
    {
        if ($this->defaultRetailer !== $retailer) {
            $this->defaultRetailer = $retailer;
            $this->updatedAt = $now;
        }

        return $this;
    }

    /**
     * Retient l'enseigne que la personne vient d'ouvrir. Sans changement, rien n'est touché (pas même la date).
     */
    public function rememberLastUsed(Retailer $retailer, DateTimeImmutable $now): self
    {
        if ($this->lastUsedRetailer !== $retailer) {
            $this->lastUsedRetailer = $retailer;
            $this->updatedAt = $now;
        }

        return $this;
    }

    /**
     * Le drive habituel ou la dernière enseigne désignent-ils celle-ci ?
     */
    public function refersTo(Retailer $retailer): bool
    {
        return $this->defaultRetailer === $retailer || $this->lastUsedRetailer === $retailer;
    }

    /**
     * L'enseigne n'est plus configurée (son réglage est retiré) : ni le drive habituel ni la dernière enseigne ne la désignent plus.
     */
    public function forget(Retailer $retailer, DateTimeImmutable $now): self
    {
        if ($this->refersTo($retailer)) {
            $this->defaultRetailer = $this->defaultRetailer === $retailer ? null : $this->defaultRetailer;
            $this->lastUsedRetailer = $this->lastUsedRetailer === $retailer ? null : $this->lastUsedRetailer;
            $this->updatedAt = $now;
        }

        return $this;
    }
}
