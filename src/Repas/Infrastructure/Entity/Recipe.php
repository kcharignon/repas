<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Recipe as RecipeModel;
use Repas\Shared\Domain\Tool\StringTool;

#[ORM\Entity]
#[ORM\Table(name: 'recipe')]
#[ORM\Index(name: 'idx_recipe_author', columns: ['author'])]
class Recipe
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column]
    private ?int $serving = null;

    #[ORM\Column(name: 'author', nullable: false)]
    private ?string $authorId = null;

    #[ORM\Column(name: "type", nullable: false)]
    private ?string $typeSlug = null;

    #[ORM\Column(name: "orignal", nullable: true)]
    private ?string $originalId = null;

    // Version de l'ETag : écrite seulement par `PostgreSQLRepository::bumpVersion` (jamais par le flush de Doctrine)
    #[ORM\Column(type: 'integer', updatable: false)]
    private int $version = 1;

    // Recette secrète (ADR 0033) : vue de son auteur seul. Faux par défaut, y compris pour une ligne écrite par le code précédent
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $secret = false;

    // Recette favorite de son auteur : faux par défaut, y compris pour une ligne écrite par le code précédent
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $favorite = false;

    public function __construct(
        ?string $id,
        ?string $name,
        ?int    $serving,
        ?string $authorId,
        ?string $typeSlug,
        ?string $original,
        bool    $secret = false,
        bool    $favorite = false,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = StringTool::slugify($name);
        $this->serving = $serving;
        $this->authorId = $authorId;
        $this->typeSlug = $typeSlug;
        $this->originalId = $original;
        $this->secret = $secret;
        $this->favorite = $favorite;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): Recipe
    {
        $this->slug = $slug;
        return $this;
    }

    public function getServing(): ?int
    {
        return $this->serving;
    }

    public function setServing(int $serving): static
    {
        $this->serving = $serving;

        return $this;
    }

    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    public function setAuthorId(?string $authorId): static
    {
        $this->authorId = $authorId;

        return $this;
    }

    public function getTypeSlug(): ?string
    {
        return $this->typeSlug;
    }

    public function setTypeSlug(?string $typeSlug): static
    {
        $this->typeSlug = $typeSlug;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function setSecret(bool $secret): static
    {
        $this->secret = $secret;

        return $this;
    }

    public function isFavorite(): bool
    {
        return $this->favorite;
    }

    public function setFavorite(bool $favorite): static
    {
        $this->favorite = $favorite;

        return $this;
    }

    public function getOriginalId(): ?string
    {
        return $this->originalId;
    }

    public function setOriginalId(?string $originalId): Recipe
    {
        $this->originalId = $originalId;
        return $this;
    }

    public static function fromModel(RecipeModel $recipe): static
    {
        return new static(
            id: $recipe->getId(),
            name: $recipe->getName(),
            serving: $recipe->getServing(),
            authorId: $recipe->getAuthorId(),
            typeSlug: $recipe->getType()->getSlug(),
            original: $recipe->getOriginalId(),
            secret: $recipe->isSecret(),
            favorite: $recipe->isFavorite(),
        );
    }

    public function updateFromModel(RecipeModel $recipe): void
    {
        $this->setName($recipe->getName());
        $this->setServing($recipe->getServing());
        $this->setTypeSlug($recipe->getType()->getSlug());
        $this->setSecret($recipe->isSecret());
        $this->setFavorite($recipe->isFavorite());
    }
}
