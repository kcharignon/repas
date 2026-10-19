# STORE-013 — Compte de relecture pour l'équipe de Google

- **Dépôt** : aucun (étape humaine, guidée par l'assistant)
- **Jalon** : M2 Conformité aux règles de Google Play
- **Taille** : S
- **Dépend de** : STORE-009, STORE-010 (le compte doit voir l'interface finale)
- **Faisable sans téléphone ni compte Play** : oui (le compte est créé sur le site) ; les identifiants sont saisis dans la console à STORE-017
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) §§ 6.e et 13 ; [ADR 0020](../../adr/0020-confirmation-d-adresse-email.md) et [0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) (statut du compte) ; skill `repas-ops`.

## Contexte

L'application demande une connexion. Sans identifiants, l'équipe de Google ne peut pas l'ouvrir : c'est une cause fréquente de refus. La console propose un champ « accès à l'application » (jusqu'à cinq jeux d'identifiants, avec des instructions). Le compte doit se comporter **comme celui de tout le monde** (règle « identique pour le relecteur »).

## Portée

1. Créer, **sur le site de production**, un compte ordinaire (pas administrateur) avec une adresse que le propriétaire contrôle (alias d'assistance ou adresse dédiée) et un mot de passe long généré par son gestionnaire.
2. Confirmer l'adresse par le lien reçu (ou fixer le statut `ACTIVE` depuis la page des utilisateurs, ADR 0021) : le relecteur ne doit pas avoir à lire un e-mail.
3. Garnir le compte avec quelques données fictives faites à la main : deux ou trois recettes, une liste de courses, un repas planifié ; **aucune donnée réelle**, aucun nom de magasin.
4. Ne pas régler de magasin ni de produits du drive (la fonction est facultative).
5. Préparer le texte « Accès à l'application » (annexe A de l'analyse) ; les **identifiants** seront saisis par le propriétaire dans la console à STORE-017 (jamais dans le chat, jamais dans le dépôt).
6. Essayer la connexion sur un navigateur et sur l'application, avec un compte qui n'a **jamais** été utilisé avec la connexion Google.

## Hors portée

La saisie dans la console (STORE-017) ; un compte de relecture pour la connexion Google (le relecteur utilise le mot de passe) ; toute fixture en production (les fixtures de démonstration sont refusées en production).

## Critères d'acceptation

- Étant donné les identifiants du compte, quand on se connecte sur kantin.eu, alors on arrive sur la liste sans autre étape (pas de confirmation à faire, pas de réglage).
- Étant donné le compte, alors il a au moins deux recettes et une liste de courses fictives, et n'est pas administrateur.
- Étant donné le dépôt, le chat et les journaux, alors le mot de passe n'y figure pas.

## Tests exigés

Aucun automatique. Preuve : le propriétaire se connecte et dit « fait ».

## Definition of Done

Compte actif et garni ; mot de passe dans le gestionnaire ; roadmap cochée avec la date.

## Pièges connus

- Un compte `PENDING` (e-mail non confirmé) fait échouer le relecteur : `403 EMAIL_NOT_VERIFIED`.
- **Ne pas changer le mot de passe** pendant l'examen ; garder le compte pour les mises à jour futures (Google revérifie à chaque examen).
- Ne pas supprimer le compte avant l'approbation ; le supprimer (ou changer le mot de passe) après avoir retiré l'accès dans la console, si le propriétaire le souhaite.
- Le compte ne doit pas disposer d'accès supplémentaires qu'un utilisateur ordinaire n'a pas (« comportement identique »).
- Si la connexion Google est allumée, le relecteur n'est pas obligé de s'en servir : le formulaire reste.
