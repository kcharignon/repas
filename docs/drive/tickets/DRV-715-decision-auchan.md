# DRV-715 — Décision « Auchan : adaptateur complet, mode assisté ou abandon » et tickets suivants

- **Dépôt** : back (`docs/drive/spikes/DRV-715-decision-auchan.md`, `docs/drive/tickets/`, `docs/adr/0027-…`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-712, DRV-713, DRV-714
- **Statut** : à faire. Effort recommandé : `medium`.
- **Lire d'abord** : les trois rapports ; [`../multi-enseignes.md`](../multi-enseignes.md) § 11 ; [`DRV-005`](DRV-005-synthese-decision-g1.md) (même forme de décision) ; [ADR 0022](../../adr/0022-remplissage-du-panier-drive.md).

## Contexte

Une décision se prend sur preuves, comme G1 pour Leclerc : l'anti-robot est-il surmontable par un agent dans la page de l'utilisateur ? le panier se remplit-il avec une quantité absolue ? le magasin se lit-il ? la connexion est-elle possible dans la WebView ?

## Portée

Écrire `docs/drive/spikes/DRV-715-decision-auchan.md` : un tableau des preuves (rapport, ligne), la décision et ses conséquences.

- **Adaptateur complet** : fixer la forme du magasin (`Store` : adresse par magasin comme Leclerc, ou magasin lu dans la page avec une adresse d'enseigne) et, si elle change, écrire le ticket back (`Store`, règle d'adresse, `DriveStoreDTO`, protocole `store`) ; fixer le motif d'identifiant de produit, le mode ordinateur ou mobile, le point d'entrée, la cadence de départ ; écrire les tickets suivants DRV-717+ (recherche, panier, lecture du magasin, entrée, détection de défi, diagnostic, recette réelle) avec leurs dépendances ; créer `hypotheses-site-reel-auchan.md` (mesuré, supposé, inconnu).
- **Mode assisté seulement** (lien de recherche par ligne, sans remplissage) : décrire le périmètre et le ticket unique ; le registre garde l'enseigne sans adaptateur d'agent.
- **Abandon** : la raison écrite, M7 clos après DRV-711, DRV-716 et DRV-717 annulés.

## Hors portée

Du code ; un avis juridique (sujet clos).

## Critères d'acceptation

- Le fichier de décision cite chaque preuve et la décision est sans ambiguïté.
- L'ADR 0027 est amendé si la décision change une règle (forme du magasin, mode assisté).
- Les tickets suivants sont écrits et ordonnés, ou la raison de l'arrêt est écrite ; `roadmap.md` à jour.

## Tests exigés

Relecture des liens relatifs.

## Definition of Done

Celle d'un ticket de documentation ; PR « Quoi / Vérifié / À relire » ; décision relue par l'utilisateur si elle touche au produit (mode assisté, abandon).

## Pièges connus

- Ne pas présenter une absence d'observation comme une absence de protection.
- Ne pas décider de lancer Auchan avant la fin de la recette de Leclerc : l'ordre est le choix de l'utilisateur (`multi-enseignes.md` § 13).
