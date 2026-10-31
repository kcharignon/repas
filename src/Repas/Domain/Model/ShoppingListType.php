<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * Un type de liste de courses (ADR 0036) : un nom que l'utilisateur crée (« Boucherie », « Marché »), propre à son compte, et les rayons
 * dont les produits vont dans la liste en cours de ce type. Un rayon est dans un seul type à la fois : le rattacher ici le retire de celui
 * qui l'avait (la clé en base est le couple propriétaire et rayon, `ShoppingListTypeRepository::save`).
 *
 * Il référence ses rayons par identifiant (ADR 0013). Le nom est de 1 à 40 caractères, sa première lettre en majuscule (le cas d'usage y
 * veille, ADR 0030), et unique par compte une fois normalisé (`sluggedName`, règle de l'application).
 */
final class ShoppingListType implements ModelInterface
{
    use ModelTrait;

    /** Types qu'un compte garde au plus : un par magasin, jamais quarante. */
    public const int MAX_PER_OWNER = 10;

    public const int MAX_NAME_LENGTH = 40;

    /**
     * @param list<string> $departmentSlugs
     */
    private function __construct(
        private string $id,
        private string $ownerId,
        private string $name,
        private string $sluggedName,
        private DateTimeImmutable $createdAt,
        private array $departmentSlugs,
    ) {
    }

    /**
     * Un type neuf, sans rayon.
     *
     * @throws ShoppingListTypeException le nom est vide, de plus de 40 caractères, ou sans lettre ni chiffre
     */
    public static function create(string $id, string $ownerId, string $name, DateTimeImmutable $createdAt): self
    {
        [$name, $sluggedName] = self::normalized($name);

        return new self($id, $ownerId, $name, $sluggedName, $createdAt, []);
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            ownerId: $datas['owner_id'],
            name: $datas['name'],
            sluggedName: $datas['slugged_name'],
            createdAt: $datas['created_at'],
            departmentSlugs: self::sorted($datas['department_slugs'] ?? []),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Identifiant du propriétaire du type (ADR 0013 règle 8 : un agrégat référence un utilisateur par son identifiant).
     */
    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Le nom normalisé : de quoi juger que deux noms sont les mêmes (« Marché » et « marche »).
     */
    public function getSluggedName(): string
    {
        return $this->sluggedName;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Les rayons rattachés à ce type, sans doublon, triés par identifiant.
     *
     * @return list<string>
     */
    public function getDepartmentSlugs(): array
    {
        return $this->departmentSlugs;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    /**
     * Le type est à cet utilisateur, sans exception : pas même pour un administrateur (ADR 0036 décision 13). Le type d'un autre est traité
     * comme inconnu : on ne révèle pas son existence.
     *
     * @throws ShoppingListTypeException
     */
    public function assertOwnedBy(string $userId): void
    {
        if (!$this->isOwnedBy($userId)) {
            throw ShoppingListTypeException::notFound();
        }
    }

    /**
     * Change le nom. Renommer un type ne renomme pas ses listes (ADR 0036 décision 15).
     *
     * @throws ShoppingListTypeException le nom est vide, de plus de 40 caractères, ou sans lettre ni chiffre
     */
    public function rename(string $name): self
    {
        [$this->name, $this->sluggedName] = self::normalized($name);

        return $this;
    }

    /**
     * Remplace le jeu COMPLET des rayons du type : ceux qui n'y sont plus sont détachés, ceux qui y sont, rattachés (et retirés du type
     * qui les avait, à l'enregistrement).
     *
     * @param list<string> $departmentSlugs
     */
    public function replaceDepartments(array $departmentSlugs): self
    {
        $this->departmentSlugs = self::sorted($departmentSlugs);

        return $this;
    }

    /**
     * @return array{0: string, 1: string} le nom sans espaces autour, et son nom normalisé
     *
     * @throws ShoppingListTypeException
     */
    private static function normalized(string $name): array
    {
        $name = trim($name);
        $sluggedName = StringTool::slugify($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH || $sluggedName === '') {
            throw ShoppingListTypeException::invalidName();
        }

        return [$name, $sluggedName];
    }

    /**
     * @param list<string> $slugs
     * @return list<string>
     */
    private static function sorted(array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        sort($slugs, SORT_STRING);

        return $slugs;
    }
}
