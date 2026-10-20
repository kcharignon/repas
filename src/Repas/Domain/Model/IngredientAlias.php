<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\IngredientAliasException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * « Pour cet utilisateur, `aliasSlug` est son ingrédient `ingredientSlug` » (ADR 0028 décision 2) : la table de correspondance
 * permanente entre un commun adopté (ou un ingrédient fusionné) et l'ingrédient de l'utilisateur. Elle sert à masquer le commun, à
 * résoudre un ancien slug reçu en écriture, à auditer une migration et à rejouer une fusion. Identifiants seulement (ADR 0013) :
 * aucun ingrédient, aucun utilisateur chargé.
 *
 * `source` dit d'où vient la ligne : `first-use`, `copy`, `migration-AAAA-MM-JJ` ou `merge`. Elle suit les fusions (l'ingrédient
 * désigné change), à la différence de `Ingredient::getAdoptedFrom()`, fait immuable. Effacée avec le compte (ADR 0021).
 */
final class IngredientAlias implements ModelInterface
{
    use ModelTrait;

    /** Longueur de la colonne `ingredient_alias.source`. */
    public const int MAX_SOURCE_LENGTH = 64;

    private function __construct(
        private string $userId,
        private string $aliasSlug,
        private string $ingredientSlug,
        private AliasKind $kind,
        private string $source,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @throws IngredientAliasException
     */
    public static function create(
        string $userId,
        string $aliasSlug,
        string $ingredientSlug,
        AliasKind $kind,
        string $source,
        DateTimeImmutable $createdAt,
    ): self {
        if ($aliasSlug === $ingredientSlug) {
            throw IngredientAliasException::pointsToItself($aliasSlug);
        }
        if ($source === '' || mb_strlen($source) > self::MAX_SOURCE_LENGTH) {
            throw IngredientAliasException::invalidSource($source);
        }

        return new self($userId, $aliasSlug, $ingredientSlug, $kind, $source, $createdAt);
    }

    public static function load(array $datas): self
    {
        return new self(
            userId: $datas['user_id'],
            aliasSlug: $datas['alias_slug'],
            ingredientSlug: $datas['ingredient_slug'],
            kind: $datas['kind'],
            source: $datas['source'],
            createdAt: $datas['created_at'],
        );
    }

    /**
     * La clé est le couple (utilisateur, slug d'alias).
     */
    public function getId(): string
    {
        return $this->userId . '|' . $this->aliasSlug;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAliasSlug(): string
    {
        return $this->aliasSlug;
    }

    public function getIngredientSlug(): string
    {
        return $this->ingredientSlug;
    }

    public function getKind(): AliasKind
    {
        return $this->kind;
    }

    public function isAdoption(): bool
    {
        return $this->kind === AliasKind::ADOPTION;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
