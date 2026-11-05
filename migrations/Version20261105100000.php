<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le prix d'un produit du drive redevient un fait COMMUN du magasin (DRV-770, amendement du 2026-11-05 de l'ADR 0032) : il vit sur la fiche
 * `drive_product` seule, mise à jour par tout compte qui accepte le produit. Cette migration ne change AUCUN schéma, elle remet les fiches à
 * niveau : avant DRV-770, la première acceptation d'un produit déjà connu ne mettait que la ligne du compte à jour (`drive_choice_product`),
 * pas la fiche, qui peut donc avoir un prix plus ancien que celui d'une ligne.
 *
 * Pour chaque fiche, le prix et la date de la ligne ACCEPTÉE la plus récemment lue sont recopiés sur la fiche quand ils sont plus récents que
 * les siens, ou quand la fiche n'a pas de prix (ou pas de date). On ne rétrograde jamais un prix et on n'efface rien : ni le libellé, ni
 * aucune ligne de compte (les colonnes `price_cents` et `price_seen_at` de `drive_choice_product` restent telles quelles, DRV-771 les
 * supprimera). Le prix et sa date vont ensemble, toujours pris sur la même ligne. Rejouable sans effet : une fois la fiche à niveau, aucune
 * ligne n'est plus récente qu'elle.
 *
 * `down` est vide : une remise à niveau ne se défait pas (un prix plus récent n'est jamais faux), sans conséquence pour un retour arrière du
 * code, qui lit alors le prix de la ligne du compte ou, à défaut, celui de la fiche.
 */
final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Données seulement : chaque fiche drive_product reçoit le prix le plus récent trouvé sur les lignes de produits acceptés (le prix devient commun, DRV-770)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE drive_product p SET price_cents = l.price_cents, price_seen_at = l.price_seen_at'
            . ' FROM ('
            . '   SELECT DISTINCT ON (cp.product_id) cp.product_id, cp.price_cents, cp.price_seen_at'
            . '   FROM drive_choice_product cp'
            . "   WHERE cp.verdict = 'ACCEPTED' AND cp.price_cents IS NOT NULL AND cp.price_seen_at IS NOT NULL"
            . '   ORDER BY cp.product_id, cp.price_seen_at DESC, cp.price_cents'
            . ' ) l'
            . ' WHERE p.id = l.product_id'
            . '   AND (p.price_cents IS NULL OR p.price_seen_at IS NULL OR l.price_seen_at > p.price_seen_at)',
        );
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : voir la description de la classe
    }
}
