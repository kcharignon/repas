# DRV-716 — Déclarer Auchan dans le registre du back, éteint

- **Dépôt** : back (`src/Drive/Domain/Model/`, `docs/api/openapi.json`, `docs/drive/samples/`, `docs/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-701, DRV-720, DRV-721 (et DRV-702 pour l'interrupteur par enseigne)
- **Statut** : fait (back, 2026-10-30, PR #330, 2026-10-30). Effort recommandé : `high`. **Décision prise** : [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md) (adaptateur complet).
- **Lire d'abord** : la décision ; [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) ; [`../multi-enseignes.md`](../multi-enseignes.md) §§ 4, 7, 8 ; [ADR 0027](../../adr/0027-plusieurs-enseignes-de-drive.md) (amendement du 2026-10-30) ; skills `repas-dev` et `repas-drive-back`.

## Contexte

Quand le registre (DRV-701), la forme du magasin (DRV-720) et l'identifiant d'offre (DRV-721) existent, Auchan devient **une entrée de registre** du back. Elle reste **éteinte** (`DRIVE_RETAILERS` ne la contient pas : DRV-702) : on peut fusionner et publier sans qu'aucun utilisateur la voie. Les registres du web, de l'agent et de l'application sont l'objet de DRV-727 et DRV-728.

## Portée

1. `Retailer::AUCHAN = 'auchan'` et son profil (le constructeur de `RetailerProfile` reçoit `storeIdSource` et `storeIdPattern` depuis DRV-720 ; un profil `EXPLICIT` s'écrit avec `storeAddressPattern` = l'adresse constante, ancrée et **sans groupe**, et `storeAddressExample` = cette adresse : voir la « Réalisation » de [DRV-720](DRV-720-magasin-a-identifiant-explicite.md)) : `name` « Auchan », `nameDe` « d’Auchan », `storeIdSource` `EXPLICIT`, `storeIdPattern`, `productIdPattern` et `offerIdPattern` (UUID : `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`), règle d'adresse constante `^https://www\.auchan\.fr/$` (exemple `https://www.auchan.fr/`). `Retailer::IDS` et OpenAPI régénérés.
2. Contrat `retailers.sample.json` : l'entrée d'Auchan, comparée par `DriveContractSamplesTest` ; un exemple de réglage Auchan (**valeurs inventées**, UUID de test) dans `settings.sample.json`.
3. L'exception du test d'architecture de DRV-701 (le nom `leclerc` n'apparaît que dans son profil) devient : chaque enseigne n'est nommée que dans `Retailer.php` / `RetailerProfile.php`.
4. Documentation : `architecture.md` §§ 5.1 et 7, `multi-enseignes.md` § 3 (le registre contient désormais deux entrées), runbook de l'enseigne dans `runbook.md` (une section « Auchan a changé » à compléter par DRV-726).

## Hors portée

Allumer Auchan (l'humain), toute modification de comportement tant que `DRIVE_RETAILERS=leclerc`.

## Critères d'acceptation

- Étant donné `DRIVE_RETAILERS=leclerc`, alors **aucun** comportement visible ne change (plans, estimations, `GET /drive/settings`).
- Étant donné `DRIVE_RETAILERS=leclerc,auchan` dans un test, alors Auchan apparaît dans `retailers`, un réglage Auchan (UUID de test, adresse constante) s'enregistre et un plan Auchan se calcule à partir de choix Auchan ; un produit Leclerc n'entre jamais dans un choix Auchan.
- Le contrat est identique dans le back et dans les exemples copiés par le front (la CI du front le vérifie).

## Tests exigés

Ceux de DRV-701, 720, 721 étendus à l'entrée réelle ; isolement à deux enseignes (réglages, produits, choix, purge du compte) ; `make test`, `make quality`, `make openapi`.

## Definition of Done

Celle du dépôt back ; **dire dans le rapport l'ordre de publication** (back avec `DRIVE_RETAILERS=leclerc`, puis site, puis application) ; aucune étiquette ; `DRIVE_RETAILERS` inchangé en production.

## Pièges connus

- Ne rien écrire d'Auchan hors du profil (nom, hôte) : `Store` vient de DRV-720, les textes de DRV-727.
- Les UUID des exemples sont **inventés** (jamais un identifiant lu sur le vrai site).
