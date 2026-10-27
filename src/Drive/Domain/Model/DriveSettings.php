<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;
use DateTimeZone;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Les réglages du drive d'un utilisateur pour une enseigne : le magasin où il retire ses courses. Un seul par utilisateur et
 * par enseigne (changer de magasin remplace le précédent). Agrégat distinct de `User` (ADR 0020).
 *
 * Il porte aussi le compteur du jour des écritures de produits de l'utilisateur (plafond `MAX_PRODUCT_WRITES_PER_DAY`, DRV-613) :
 * les réglages sont déjà lus par chaque écriture du drive, le compteur ne coûte donc aucune lecture de plus, et il disparaît
 * avec le compte.
 */
final class DriveSettings implements ModelInterface
{
    use ModelTrait;

    /**
     * Écritures de produits (`ADD_PRODUCT`, `REJECT_PRODUCT`, `REFRESH_PRICE`) qu'un utilisateur peut envoyer par jour (jour UTC) :
     * une liste de courses en demande quelques dizaines, un compte qui en envoie des milliers remplit la table commune des
     * produits (constat C2 de la revue de sécurité). Au-delà : `DRIVE_LIMIT_REACHED`, rien n'est enregistré.
     */
    public const int MAX_PRODUCT_WRITES_PER_DAY = 5000;

    private function __construct(
        private string $userId,
        private Retailer $retailer,
        private Store $store,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $productWritesDay,
        private int $productWritesCount,
    ) {
    }

    public static function create(string $userId, Retailer $retailer, Store $store, DateTimeImmutable $now): self
    {
        return new self($userId, $retailer, $store, $now, null, 0);
    }

    /**
     * @param array{user_id: string, retailer: Retailer, store: Store, updated_at: DateTimeImmutable, product_writes_day?: ?DateTimeImmutable, product_writes_count?: int} $datas
     */
    public static function load(array $datas): self
    {
        return new self(
            $datas['user_id'],
            $datas['retailer'],
            $datas['store'],
            $datas['updated_at'],
            $datas['product_writes_day'] ?? null,
            $datas['product_writes_count'] ?? 0,
        );
    }

    /**
     * Identifiant composite : un réglage est désigné par son utilisateur et son enseigne.
     */
    public function getId(): string
    {
        return $this->userId . ':' . $this->retailer->value;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getRetailer(): Retailer
    {
        return $this->retailer;
    }

    public function getStore(): Store
    {
        return $this->store;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Jour (UTC) du dernier compte d'écritures de produits, `null` si l'utilisateur n'en a jamais envoyé.
     */
    public function getProductWritesDay(): ?DateTimeImmutable
    {
        return $this->productWritesDay;
    }

    /**
     * Écritures de produits déjà comptées pour `getProductWritesDay()`.
     */
    public function getProductWritesCount(): int
    {
        return $this->productWritesCount;
    }

    public function changeStore(Store $store, DateTimeImmutable $now): self
    {
        $this->store = $store;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Le jour (UTC, à minuit) auquel se rattachent les écritures de produits faites à `$now` : le compteur repart de zéro chaque jour UTC.
     */
    public static function productWritesDayOf(DateTimeImmutable $now): DateTimeImmutable
    {
        return new DateTimeImmutable($now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * Compte `$count` écritures de produits pour le jour de `$now` (le compteur repart de zéro chaque jour UTC). Tout ou rien :
     * au-delà du plafond, rien n'est compté.
     *
     * C'est la règle sur un modèle en mémoire (le double de test s'en sert). Le dépôt réel applique la même règle en UNE instruction
     * (`DriveSettingsRepository::reserveProductWrites()`) : deux requêtes simultanées d'un même compte ne lisent pas le même compteur.
     *
     * @throws DriveException `limitReached()` (422)
     */
    public function recordProductWrites(int $count, DateTimeImmutable $now): self
    {
        $today = self::productWritesDayOf($now);
        $alreadyToday = $this->productWritesDay?->format('Y-m-d') === $today->format('Y-m-d') ? $this->productWritesCount : 0;

        if ($alreadyToday + $count > self::MAX_PRODUCT_WRITES_PER_DAY) {
            throw DriveException::limitReached();
        }

        $this->productWritesDay = $today;
        $this->productWritesCount = $alreadyToday + $count;

        return $this;
    }
}
