<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Passe les emails des utilisateurs en minuscules (l'email est comparé sans tenir compte de la casse)";
    }

    public function up(Schema $schema): void
    {
        // Deux comptes dont les emails ne diffèrent que par la casse deviendraient un doublon : on s'arrête avant de rien modifier
        $duplicates = $this->connection->fetchFirstColumn('SELECT lower(trim(email)) FROM "user" GROUP BY lower(trim(email)) HAVING count(*) > 1');
        $this->abortIf($duplicates !== [], sprintf('Emails en double une fois en minuscules, à fusionner ou renommer à la main : %s', implode(', ', $duplicates)));

        $this->addSql('UPDATE "user" SET email = lower(trim(email))');
    }

    public function down(Schema $schema): void
    {
        // La casse d'origine n'est pas conservée : rien à restaurer.
        $this->throwIrreversibleMigrationException();
    }
}
