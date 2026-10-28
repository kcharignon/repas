<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Première lettre des noms de recettes et d'ingrédients en majuscule (ADR 0030) : la règle est désormais appliquée à l'écriture par les
 * gestionnaires de commande ; cette migration y met les noms DÉJÀ enregistrés (`recipe.name`, `ingredient.name`, les seules colonnes qui
 * portent un tel nom : le schéma réel n'en a pas d'autre). Seule la première lettre change (« pâtes carbonara » devient « Pâtes carbonara »,
 * « de la farine T55 » devient « De la farine T55 »), le reste du nom est conservé tel quel.
 *
 * Fait en PHP (`mb_*`, lecture puis mise à jour des seules lignes qui changent), jamais avec `upper()` de SQL : la locale de PostgreSQL sur
 * l'image Alpine de production traite mal les accents et les ligatures. Ni les slugs (`ingredient.slug`, `recipe.slug`) ni `slugged_name`
 * ne sont touchés : ils sont déjà en minuscules sans accent et ne dépendent pas de la casse du nom. La version d'une recette ne monte pas :
 * ce n'est pas une modification de l'utilisateur, et personne ne met la réponse de l'API en cache d'après sa version.
 *
 * Données seulement, aucune colonne : compatible avec la version précédente du code (ADR 0017 règle 6), qui lit un nom en majuscule comme
 * n'importe quel autre. Rejouable sans effet : un nom déjà bon ne produit aucune instruction, et chaque mise à jour vérifie que le nom est
 * toujours celui qui a été lu (`AND name = ancien nom`) avant de l'écrire. `down` ne fait rien : l'ancienne casse ne se reconstitue pas.
 *
 * La règle est recopiée ici (`StringTool::capitalizeFirst`) et non importée : une migration doit rester lisible et stable seule, même si la
 * règle de l'application évolue un jour. Un test (`CapitalizeNamesMigrationTest`) compare les deux sur tous les premiers caractères.
 */
final class Version20261028160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Première lettre des noms de recettes et d'ingrédients en majuscule (les noms déjà bons ne sont pas touchés)";
    }

    public function up(Schema $schema): void
    {
        foreach ([
            ['ingredient', 'slug'],
            ['recipe', 'id'],
        ] as [$table, $key]) {
            foreach ($this->connection->iterateAssociative(sprintf('SELECT %s AS k, name FROM %s ORDER BY %s', $key, $table, $key)) as $row) {
                $name = (string) $row['name'];
                $capitalized = self::capitalizeFirst($name);
                if ($capitalized === $name) {
                    continue;
                }
                $this->addSql(
                    sprintf('UPDATE %s SET name = ? WHERE %s = ? AND name = ?', $table, $key),
                    [$capitalized, $row['k'], $name],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // L'ancienne casse ne se reconstitue pas : rien à défaire
        $this->addSql('SELECT 1');
    }

    /**
     * La première LETTRE du texte en majuscule (celle de la mise en forme de titre : « ﬁlet » donne « Filet »), le reste tel quel ; les
     * espaces de tête sont conservés ; un premier caractère qui n'est pas une lettre (chiffre, parenthèse, apostrophe), ou une lettre déjà
     * en majuscule, laisse le texte inchangé ; une chaîne vide ou qui n'est pas de l'UTF-8 valide aussi.
     */
    public static function capitalizeFirst(string $text): string
    {
        if (preg_match('/^(\s*)(.)(.*)$/su', $text, $parts) !== 1) {
            return $text;
        }
        if (preg_match('/^[\p{Lu}\p{Lt}]$/u', $parts[2]) === 1) {
            return $text;
        }

        return $parts[1] . mb_convert_case($parts[2], MB_CASE_TITLE, 'UTF-8') . $parts[3];
    }
}
