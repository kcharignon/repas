# DRV-002 — Exécution du banc d'essai sur le téléphone et rapport

- **Dépôt** : — (tâche humaine ; rapport dans le dépôt back)
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : DRV-001 fusionné
- **Statut** : à faire
- **Lire d'abord** : `drive-agent/lab/PROCEDURE.md` du dépôt front, [`../spikes/DRV-002-rapport.md`](../spikes/DRV-002-rapport.md)

## Contexte

Seul le téléphone réel de l'utilisateur, avec son navigateur et son compte, peut dire si le favori tourne sur Leclerc et si DataDome laisse passer les requêtes. Ce ticket est fait par l'utilisateur (ou un agent qui le guide **une étape à la fois**, sans jamais lui demander un identifiant ni un mot de passe).

## Portée

1. Créer le favori de test sur Chrome Android (suivre `PROCEDURE.md`).
2. Ouvrir le drive Leclerc de son magasin, se connecter lui-même, choisir son magasin.
3. Lancer chaque essai du banc d'essai et noter le résultat dans `docs/drive/spikes/DRV-002-rapport.md` (19 lignes, « non testé » avec la raison si impossible).
4. Si possible, refaire les essais d'identifiants et de contenu (lignes 15 à 17) avec un deuxième magasin.
5. Coller dans le rapport le résultat copié du lot de 30 recherches (test 11), puis noter dans la ligne 18 combien de fois le bon produit est dans les 3 premiers.
6. Ouvrir une PR docs avec le rapport rempli.

## Hors portée

Toute modification de code. Toute commande ou validation de panier réel.

## Critères d'acceptation

- Les 19 lignes du rapport sont remplies ou marquées « non testé » avec la raison.
- Le rapport indique le modèle du téléphone, la version d'Android et de Chrome.
- Le rapport ne contient aucun identifiant, cookie, jeton, adresse e-mail ni numéro de magasin personnel.

## Pièges connus

- Si le favori ne se lance pas, noter précisément ce qui se passe (rien, message, page blanche) ; c'est déjà un résultat (repli Firefox ou mode assisté).
- Un blocage (403) peut apparaître : recharger le site dans Chrome le lève ; noter après combien de requêtes.
- Ne jamais coller de mot de passe, de code reçu par SMS ni de cookie dans une conversation ou un rapport.
