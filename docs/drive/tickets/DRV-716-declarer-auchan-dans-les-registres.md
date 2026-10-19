# DRV-716 — Déclarer Auchan dans les quatre registres, éteint

- **Dépôt** : back (`Retailer`, contrat, docs) puis front (web `retailers.ts`, agent `retailers/`, `android/core` `RetailerSites`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-715 (décision « adaptateur complet » ou « mode assisté »), DRV-701, DRV-704, DRV-706, DRV-708
- **Statut** : à faire (après les spikes). Effort recommandé : `high`.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 4, 7, 8, 11 ; `docs/drive/spikes/DRV-715-decision-auchan.md` ; skill de chaque couche ; [ADR 0027](../../adr/0027-plusieurs-enseignes-de-drive.md).

## Contexte

Quand la forme du magasin, les hôtes, le mode (ordinateur ou mobile) et le motif d'identifiant sont connus, Auchan devient une entrée de registre dans chaque couche. **Elle reste éteinte** (`DRIVE_RETAILERS` ne la contient pas) : on peut fusionner et publier sans qu'aucun utilisateur la voie.

## Portée

1. **Back** : `Retailer::AUCHAN = 'auchan'`, son profil (`name` « Auchan », `nameDe` « d’Auchan », règle d'adresse du magasin ou de l'enseigne selon la décision, motif d'identifiant de produit, plafonds), `Retailer::IDS`, contrat `retailers.sample.json` ; OpenAPI régénéré ; **si la décision change la forme du magasin** : ticket distinct écrit par DRV-715, non fait ici.
2. **Agent** : entrée `auchan` du registre avec un adaptateur **qui ne fait rien d'utile** (voir DRV-717) ; hôtes ; protocole : `retailer` ∈ `{leclerc, auchan}` ; exemples valides et invalides.
3. **Application** : `RetailerSite` d'Auchan (domaine, accueil, mode, formes de page) ; origines du pont ; `app.info.retailers` annonce `auchan` **seulement** si la variante de l'application l'embarque (décision : drapeau de construction ; en production il reste `["leclerc"]` tant que DRV-717 n'est pas recetté).
4. **Web** : entrée du registre `retailers.ts`, section des conditions d'utilisation d'Auchan (texte validé par l'utilisateur ; sujet des CGU clos : ne décrire que ce que Repas fait), accueil du drive d'Auchan.
5. **Garde-fous** : tests d'isolement à deux enseignes **réelles** (les domaines de Leclerc et d'Auchan) : une page de l'une n'est pas autorisée dans l'écran de l'autre.
6. **Ordre de publication** : back, site web, puis application et agent ; `DRIVE_RETAILERS` inchangé.

## Hors portée

Allumer Auchan (l'humain, après recette), l'adaptateur réel (DRV-717), tout fait non confirmé par un spike.

## Critères d'acceptation

- Étant donné `DRIVE_RETAILERS=leclerc`, alors **aucun** comportement visible ne change (plans, textes, écrans, `GET /drive/settings`).
- Étant donné `DRIVE_RETAILERS=leclerc,auchan` sur un environnement de test, alors Auchan apparaît dans « Mes drives » et la sélection ; un plan `auchan` envoyé à une application qui ne l'annonce pas est refusé.
- Le contrat `retailers.sample.json` est identique dans les quatre couches (test de concordance).

## Tests exigés

Ceux de DRV-701, 704, 706, 708 étendus à l'entrée réelle ; tests d'isolement ; `make test`, `make quality`, `make check`, `make android-test`, `make agent-verify`.

## Definition of Done

Celle de chaque dépôt ; **dire dans le rapport l'ordre de publication** (front avant application) ; aucune étiquette ; `docs/drive/architecture.md` et `multi-enseignes.md` § 3 mis à jour.

## Pièges connus

- Une origine d'Auchan mal écrite dans le pont est une faille : tests d'adresses hostiles identiques à ceux de Leclerc (DRV-706).
- Ne pas copier le texte des conditions d'utilisation de Leclerc : chaque enseigne a le sien.
