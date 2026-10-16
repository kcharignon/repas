<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016090000 extends AbstractMigration
{
    /** Icônes de rayon : slug => identifiant Flaticon (CDN 128 px). */
    private const DEPARTMENTS = [
        'fruit-et-legume' => 1147832,
        'fromage' => 3753482,
        'yaourt' => 1047510,
        'gateau' => 2435678,
        'aperitif' => 3814603,
        'boucherie' => 1134447,
        'alcool' => 920541,
        'boisson' => 2405597,
        'surgele' => 3082004,
        'conserve' => 2916046,
        'aide-patissier' => 992767,
        'cereale' => 5009812,
        'produit-menager' => 3899392,
        'boulangerie' => 3081918,
        'sauce' => 3106132,
        'animal' => 2619232,
        'divers' => 699044,
        'cuisine-du-monde' => 1717511,
        'hygiene' => 3212855,
    ];

    /** Rayons présents seulement en production (absents des fixtures) : slug => identifiant Flaticon. */
    private const PRODUCTION_ONLY_DEPARTMENTS = [
        'petit-dejeuner' => 887396,
    ];

    /**
     * Autre icône Flaticon qu'un rayon des fixtures porte en production : slug => identifiant.
     * Les deux anciennes URLs mènent au même fichier local ; `down()` ne restaure que celle des fixtures.
     */
    private const DEPARTMENT_ALTERNATIVES = [
        'hygiene' => 4990880,
    ];

    /** Icônes d'ingrédient : slug de l'ingrédient => [identifiant Flaticon (CDN 64 px), fichier local]. */
    private const INGREDIENTS = [
        'avocat' => [135609, 'avocat'],
        'nutella' => [135605, 'nutella'],
        'tomate' => [135702, 'tomate'],
    ];

    public function getDescription(): string
    {
        return 'Remplace les icônes Flaticon (CDN) des rayons et de 3 ingrédients par les images locales de public/images';
    }

    public function up(Schema $schema): void
    {
        foreach (self::DEPARTMENTS as $slug => $id) {
            $this->swap('department', $slug, $this->departmentCdnUrl($id), $this->departmentPath($slug));
        }

        foreach ([...self::PRODUCTION_ONLY_DEPARTMENTS, ...self::DEPARTMENT_ALTERNATIVES] as $slug => $id) {
            $this->swap('department', $slug, $this->departmentCdnUrl($id), $this->departmentPath($slug));
        }

        foreach (self::INGREDIENTS as $slug => [$id, $file]) {
            $this->swap('ingredient', $slug, $this->ingredientCdnUrl($id), $this->ingredientPath($file));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::DEPARTMENTS as $slug => $id) {
            $this->swap('department', $slug, $this->departmentPath($slug), $this->departmentCdnUrl($id));
        }

        foreach (self::PRODUCTION_ONLY_DEPARTMENTS as $slug => $id) {
            $this->swap('department', $slug, $this->departmentPath($slug), $this->departmentCdnUrl($id));
        }

        foreach (self::INGREDIENTS as $slug => [$id, $file]) {
            $this->swap('ingredient', $slug, $this->ingredientPath($file), $this->ingredientCdnUrl($id));
        }
    }

    /** Ne touche que les lignes encore sur l'ancienne valeur : une image choisie depuis reste en place. */
    private function swap(string $table, string $slug, string $from, string $to): void
    {
        $this->addSql(
            sprintf('UPDATE %s SET image = ? WHERE slug = ? AND image = ?', $table),
            [$to, $slug, $from],
        );
    }

    private function departmentCdnUrl(int $id): string
    {
        return sprintf('https://cdn-icons-png.flaticon.com/128/%d/%d.png', intdiv($id, 1000), $id);
    }

    private function ingredientCdnUrl(int $id): string
    {
        return sprintf('https://cdn-icons-png.flaticon.com/64/%d/%d.png', intdiv($id, 1000), $id);
    }

    private function departmentPath(string $slug): string
    {
        return sprintf('images/department/%s.png', $slug);
    }

    private function ingredientPath(string $file): string
    {
        return sprintf('images/ingredient/%s.png', $file);
    }
}
