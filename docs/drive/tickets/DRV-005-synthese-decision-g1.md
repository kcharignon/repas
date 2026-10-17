# DRV-005 — Synthèse et décision G1

- **Dépôt** : back (`docs/`)
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : DRV-002, DRV-003, DRV-004
- **Statut** : à faire
- **Lire d'abord** : les trois rapports `docs/drive/spikes/`, [ADR 0022](../../adr/0022-remplissage-du-panier-drive.md), [`../roadmap.md`](../roadmap.md)

## Contexte

La décision G1 déclenche tout le code produit. Elle se prend sur trois preuves : le favori tourne sur le téléphone et passe DataDome (DRV-002), les CGU n'interdisent pas l'usage (DRV-003), les en-têtes autorisent la solution (DRV-004).

## Portée

Écrire `docs/drive/spikes/DRV-005-decision-g1.md` : un tableau des preuves, la décision et ses conséquences.

- **Go** : passer l'ADR 0022 à `Accepted` ; choisir P1 (cadre natif) ou P2 (liste de résultats de l'agent) ; fixer la clé de mémorisation (par magasin ou commune, selon DRV-002 lignes 15 et 16) ; décider de l'ouverture (lien d'intention ou lien normal) ; ajuster les tickets concernés (DRV-303, 306, 402, 404, 403) si une hypothèse a changé ; fixer si les indices de recherche par ingrédient deviennent obligatoires (si moins de 70 % de bons résultats dans les 3 premiers) ; mettre à jour `roadmap.md`.
- **Go avec repli Firefox** : si le favori Chrome est bloqué mais pas Firefox : écrire le ticket de véhicule alternatif (gestionnaire de scripts ou extension) et réordonner.
- **Mode assisté seulement** : si l'automatisation est bloquée ou interdite : passer l'ADR 0022 à `Accepted` avec la décision « mode assisté », réécrire le périmètre (écran de revue + liens de recherche par ligne + choix mémorisés saisis par l'utilisateur) et remplacer les tickets M3 par un ticket unique.
- **Abandon** : ADR 0022 à `Rejected`, roadmap close, raison écrite.

## Hors portée

Toute implémentation.

## Critères d'acceptation

- Le fichier de décision cite chaque preuve (rapport, ligne) et la décision est sans ambiguïté.
- ADR 0022 a un statut final ; `roadmap.md` et les tickets touchés sont à jour.
- Si Go : les tickets M1 à M3 peuvent démarrer sans question ouverte ; sinon le nouveau périmètre est écrit.
