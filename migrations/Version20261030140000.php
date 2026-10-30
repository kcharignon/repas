<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « plusieurs enseignes de drive » (DRV-721) : `drive_product.retailer_offer_id`, l'identifiant de l'offre du produit dans le
 * magasin (40 caractères au plus, comme `retailer_product_id` : un UUID de 36 caractères y tient). Certaines enseignes ont deux
 * identifiants par produit, celui du produit et celui de son offre, nécessaire à l'ajout au panier ; la mémoriser évite une recherche
 * au prochain passage. Facultative : une enseigne sans offre ne l'écrit jamais.
 *
 * Colonne nullable seule (additive) : la version précédente du code ne la connaît pas, ne la lit pas et ne l'écrit pas, et son
 * `INSERT … ON CONFLICT` nomme ses colonnes une à une. Aucun index : la clé naturelle reste `(retailer, store_id, retailer_product_id)`.
 *
 * `down` retire la colonne (donnée de commodité, relue par l'agent au passage suivant : rien de précieux ne se perd).
 */
final class Version20261030140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne drive_product.retailer_offer_id VARCHAR(40) NULL (identifiant d\'offre du produit)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_product ADD retailer_offer_id VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_product DROP retailer_offer_id');
    }
}
