# DRV-205 — `PackCalculator` : nombre de produits pour une quantité

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : M
- **Dépend de** : DRV-201 (et DRV-105 pour `Dimension`)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 6 ; ADR 0002 (domaine pur).

## Contexte

La liste dit « 300 g de farine » ou « 2 paquets » ; le drive vend « farine 1 kg ». Le nombre de produits à mettre au panier se calcule dans le back, par une fonction pure testée à la table. Aucune dépendance au framework ni à Doctrine.

## Portée

`src/Drive/Domain/Service/PackCalculator.php` (classe `final`, sans état) :

```php
public function packsFor(LineQuantity $line, ?ProductContent $content): PackResult
```

- `LineQuantity` (valeur) : `float $quantity`, `?Dimension $dimension`, `?float $baseFactor` (`Dimension` : `Repas\Shared\Domain\Model\Dimension`).
- `ProductContent` : `float $value`, `ContentUnit $unit` ; `ContentUnit` porte `dimension()` et `factor()` (G 1, KG 1000, ML 1, CL 10, L 1000, PIECE 1 ; dimensions WEIGHT, WEIGHT, VOLUME, VOLUME, VOLUME, COUNT).
- `PackResult` : `?int $packs`, `PackStatus $status` (`COMPUTED` | `TO_CHECK`).

Règles (dans l'ordre) :
1. Dimension de la ligne `PACKAGE` : `packs = ceil(quantity)`, `COMPUTED` (le contenu est ignoré).
2. Dimension `COUNT` : contenu de dimension `COUNT` → `ceil(quantity / content.value)`, `COMPUTED` ; contenu absent ou d'une autre dimension → `ceil(quantity)`, `TO_CHECK`.
3. Dimension `WEIGHT` ou `VOLUME` et contenu de même dimension → `ceil(quantity × baseFactor / (content.value × content.factor))`, `COMPUTED`.
4. Tout autre cas (dimension de ligne nulle, dimensions différentes, contenu absent pour poids/volume) → `packs = null`, `TO_CHECK`.
5. Arrondi supérieur avec tolérance : si le quotient est à moins de 1e-9 d'un entier, prendre cet entier ; minimum 1 ; plafond 99 (au-delà : 99, `TO_CHECK`). Quantité ≤ 0 ou non finie : `packs = null`, `TO_CHECK`.

## Hors portée

Conversions par ingrédient (3 oignons → un filet de 1 kg) : backlog DRV-605.

## Critères d'acceptation (tests à table, au minimum)

| Ligne | Contenu | Résultat |
|---|---|---|
| 2 paquets (PACKAGE) | 500 g | 2, COMPUTED |
| 300 g (WEIGHT, 1) | 1 kg | 1, COMPUTED |
| 1,2 kg (WEIGHT, 1000) | 500 g | 3, COMPUTED |
| 750 g (WEIGHT, 1) | 1000 g | 1, COMPUTED |
| 1000 g (WEIGHT, 1) | 1000 g | 1, COMPUTED (pas de 2 par flottant) |
| 30 cl (VOLUME, 10) | 1 l | 1, COMPUTED |
| 6 unités (COUNT) | 6 pièces | 1, COMPUTED |
| 8 unités (COUNT) | 6 pièces | 2, COMPUTED |
| 3 unités (COUNT) | aucun | 3, TO_CHECK |
| 300 g (WEIGHT) | 6 pièces | null, TO_CHECK |
| 300 g (WEIGHT) | aucun | null, TO_CHECK |
| quantité 0 ou négative | tout | null, TO_CHECK |
| 500 paquets | tout | 99, TO_CHECK |

## Tests exigés

Tests unitaires du domaine (aucune base, aucun kernel), chaque ligne du tableau + bornes et flottants (0,1 + 0,2).

## Definition of Done

Celle du skill `repas-dev` § 4 (`make test`, `make quality`).
