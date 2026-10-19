<?php

namespace Repas\User\Domain\Model;


use Repas\User\Domain\Exception\AppVersionException;

/**
 * Version de l'application installée (`0.1.8`), telle que l'application la donne (DRV-637) : trois nombres séparés par des
 * points, 20 caractères au plus, rien d'autre (ni suffixe `-dev`, ni quatrième nombre, ni espace).
 *
 * Deux versions se comparent **nombre par nombre**, jamais comme des textes : `0.1.10` est plus récente que `0.1.9`, `1.0.0`
 * que `0.99.99`. Un zéro en tête ne change rien (`0.1.08` vaut `0.1.8`). La version vient de l'application, une source qui
 * n'est pas fiable : elle sert à informer (inviter à mettre à jour), jamais à accorder un droit.
 */
final readonly class AppVersion
{
    public const int MAX_LENGTH = 20;

    /** Le `D` final interdit le saut de ligne que `$` accepterait sinon en fin de texte. */
    public const string PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+$/D';

    /**
     * @param array{int, int, int} $numbers
     */
    private function __construct(
        private string $value,
        private array $numbers,
    ) {
    }

    /**
     * @throws AppVersionException version mal formée (`422 INVALID_APP_VERSION`)
     */
    public static function fromString(string $version): self
    {
        return self::tryFromString($version) ?? throw AppVersionException::invalidVersion();
    }

    /**
     * La version, ou `null` si le texte n'en est pas une (vide, mal formé, trop long) : pour les réglages du serveur, où une
     * valeur absente ou fausse ne doit jamais faire échouer une réponse.
     */
    public static function tryFromString(?string $version): ?self
    {
        if ($version === null || strlen($version) > self::MAX_LENGTH || preg_match(self::PATTERN, $version) !== 1) {
            return null;
        }

        // 20 caractères au plus, dont deux points et deux autres nombres : chacun a 16 chiffres au plus et tient dans un entier
        // de 64 bits (la comparaison reste exacte)
        [$major, $minor, $patch] = array_map('intval', explode('.', $version));

        return new self($version, [$major, $minor, $patch]);
    }

    public function isOlderThan(self $other): bool
    {
        return $this->numbers < $other->numbers;
    }

    public function isEqual(self $other): bool
    {
        return $this->numbers === $other->numbers;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
