<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Repas\Domain\Model\ShoppingList as ShoppingListModel;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;

#[ORM\Entity]
#[ORM\Table(name: 'shopping_list')]
#[ORM\Index(name: 'idx_shopping_list_owner', columns: ['owner', 'status'])]
class ShoppingList
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $id = null;

    #[ORM\Column(name: 'owner', nullable: false)]
    private ?string $ownerId = null;

    #[ORM\Column(name: 'created_at')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'string', enumType: Status::class)]
    private ?Status $status = null;

    #[ORM\Column(name: 'name', nullable: true)]
    private ?string $name = null;

    // Date d'envoi au drive (statut SENT, ADR 0023) ; null sinon
    #[ORM\Column(name: 'sent_at', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    // Type de la liste (ADR 0036) ; null = la liste principale. Lu par LST-004
    #[ORM\Column(name: 'list_type_id', length: 36, nullable: true)]
    private ?string $listTypeId = null;

    // Version de l'ETag : écrite seulement par `PostgreSQLRepository::bumpVersion` (jamais par le flush de Doctrine)
    #[ORM\Column(type: 'integer', updatable: false)]
    private int $version = 1;

    /**
     * Le registre caché des parties d'ingrédient de la liste (ADR 0038 décision 6) : `{ "<slug de la partie>": {parent, quantity, equivalent} }`,
     * `null` pour toute liste qui n'a pas de partie. Du JSON gardé tel quel : le modèle le lit comme une entrée non fiable (`PartShare::registryFrom`),
     * donc la propriété n'est pas typée (une valeur de la base qui n'est pas un objet ne doit jamais empêcher de lire la liste).
     */
    #[ORM\Column(name: 'part_shares', type: 'json', nullable: true, options: ['jsonb' => true])]
    private mixed $partShares = null;

    /**
     * @param ?array<string, mixed> $partShares
     */
    public function __construct(
        string            $id,
        string            $ownerId,
        DateTimeImmutable $createdAt,
        Status            $status,
        ?string            $name,
        ?DateTimeImmutable $sentAt = null,
        ?string            $listTypeId = null,
        ?array             $partShares = null,
    ) {
        $this->id = $id;
        $this->ownerId = $ownerId;
        $this->createdAt = $createdAt;
        $this->status = $status;
        $this->name = $name;
        $this->sentAt = $sentAt;
        $this->listTypeId = $listTypeId;
        $this->partShares = $partShares;
    }

    public static function fromModel(ShoppingListModel $shoppingListModel): static
    {
        return new static (
            id: $shoppingListModel->getId(),
            ownerId: $shoppingListModel->getOwnerId(),
            createdAt: $shoppingListModel->getCreatedAt(),
            status: $shoppingListModel->getStatus(),
            name: $shoppingListModel->getName(),
            sentAt: $shoppingListModel->getSentAt(),
            listTypeId: $shoppingListModel->getListTypeId(),
            partShares: PartShare::registryToArray($shoppingListModel->getPartShares()),
        );
    }

    public function updateFromModel(ShoppingListModel $shoppingListModel): void
    {
        $this->status = $shoppingListModel->getStatus();
        $this->name = $shoppingListModel->getName();
        $this->sentAt = $shoppingListModel->getSentAt();
        $this->listTypeId = $shoppingListModel->getListTypeId();

        // Comparaison large (`!=`) : la base rend `2` là où le modèle écrit `2.0` et jsonb range les clés à sa façon, ce qui n'est pas un changement
        // (Doctrine compare strictement et écrirait la colonne à chaque enregistrement d'une liste qui a des parties)
        $partShares = PartShare::registryToArray($shoppingListModel->getPartShares());
        if ($this->partShares != $partShares) {
            $this->partShares = $partShares;
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    public function getPartShares(): mixed
    {
        return $this->partShares;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getStatus(): ?Status
    {
        return $this->status;
    }

    public function setStatus(?Status $status): ShoppingList
    {
        $this->status = $status;
        return $this;
    }

    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    public function setOwnerId(?string $ownerId): static
    {
        $this->ownerId = $ownerId;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): ShoppingList
    {
        $this->name = $name;
        return $this;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getListTypeId(): ?string
    {
        return $this->listTypeId;
    }
}
