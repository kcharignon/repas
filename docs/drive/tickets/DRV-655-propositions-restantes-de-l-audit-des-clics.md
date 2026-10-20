# DRV-655 — Propositions restantes de l'audit des clics

- **Dépôt** : front (site, `drive-agent/`, application Android)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S-M
- **Dépend de** : [DRV-648](DRV-648-envoi-sans-clic-inutile.md) (fait, PR front #180)
- **Statut** : à faire, non urgent
- **Lire d'abord** : `docs/drive/principes-produit.md` (règles 1 à 3), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`.

## Contexte

L'audit des clics de DRV-648 a fait deux propositions de plus que celles livrées. Règle du produit : une intention = un clic.

## Portée

1. « Choisis ton magasin » au premier envoi : ouvrir la lecture du magasin sans nouveau clic.
2. « Reprendre là où tu en étais ? » alors que l'application a déjà proposé « Reprendre » : reprendre directement.
3. Écran de fin après « Arrêter » : revenir directement dans Repas.

## À garder (décisions)

- « Utiliser ce magasin » reste : c'est la confirmation d'un choix, pas un clic inutile.
- **Piste 6** (ouvrir la page de magasin mémorisée sans passer par l'accueil) : à **mesurer sur le vrai site avant de la coder** (par l'humain ou sur son téléphone, jamais par un agent : interdit de contacter le site d'une enseigne). Pas de code tant que la mesure n'est pas faite.

## Critères d'acceptation

- Chaque proposition retenue enlève un clic mesuré sur le téléphone de test, sans perdre d'information ni de sécurité (rien n'est envoyé ou arrêté par erreur).

## Definition of Done

Celle du dépôt front ; clics avant et après notés dans la PR.
