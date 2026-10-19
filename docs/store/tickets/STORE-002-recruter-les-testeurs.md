# STORE-002 — Recruter au moins 15 testeurs pour le test fermé

- **Dépôt** : aucun (étape humaine, guidée par l'assistant)
- **Jalon** : M0 Décisions, testeurs, compte
- **Taille** : S (une demi-heure de messages, quelques jours d'attente)
- **Dépend de** : — (à lancer **tout de suite** : c'est ce qui décide du calendrier)
- **Faisable sans téléphone ni compte Play** : oui pour le propriétaire (les testeurs, eux, ont besoin d'un téléphone Android et d'un compte Google)
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 2 et § 10 ; skill `repas-ops` (une étape par message, aucune donnée personnelle dans le chat).

## Contexte

Avec un compte développeur personnel créé après le 2023-11-13, Google exige un test fermé avec **au moins 12 testeurs inscrits en continu pendant 14 jours** avant d'accorder la production (page « Exigences de test », consultée le 2026-10-19). Un testeur qui se désinscrit puis revient perd ses jours. Il faut donc un groupe stable, avec de la marge, prêt le jour où le test fermé démarre.

## Portée

1. Dresser la liste des personnes : au moins **15**, chacune avec **un téléphone Android** et **un compte Google** (Gmail ou autre compte Google ; un iPhone ne compte pas). Famille, amis, collègues : peu importe, ils n'ont pas besoin de se servir de l'application tous les jours (Google demande seulement qu'ils restent inscrits ; la demande de production pose aussi des questions sur leurs retours, à lire à l'écran).
2. Pour chacune : l'adresse du **compte Google** (celle avec laquelle le téléphone est connecté au Play Store) et son accord pour qu'elle serve de liste de testeurs.
3. Garder cette liste **hors du dépôt et hors du chat** (gestionnaire de mots de passe ou carnet privé) : ce sont des données personnelles de tiers. Elle sera saisie par le propriétaire dans la console (STORE-016, STORE-018).
4. Envoyer le message type (à adapter, tutoiement) :

   > Je publie mon application de recettes et de courses sur Google Play. Pour que Google m'autorise à la publier, il faut que 12 personnes l'aient sur leur téléphone Android pendant 14 jours. Tu peux m'aider ? Il suffit de me donner l'adresse de ton compte Google. Je t'enverrai un lien : tu touches « Devenir testeur », tu installes l'application, et tu ne te désinscris pas pendant deux semaines. Tu peux t'en servir ou non.

5. Le jour du test fermé (STORE-018) : relancer ceux qui n'ont pas installé.

## Hors portée

Le compte développeur (STORE-003) ; la saisie des adresses dans la console (STORE-016, STORE-018) ; tout publipostage ou publicité.

## Critères d'acceptation

- Étant donné la liste, quand on la compte, alors elle compte au moins 15 adresses de comptes Google de personnes qui ont un téléphone Android et ont dit oui.
- Étant donné le dépôt et le chat, alors aucune adresse de testeur n'y figure.

## Tests exigés

Aucun automatique. Preuve : le propriétaire dit « j'ai 15 oui ».

## Definition of Done

La liste existe chez le propriétaire ; la roadmap est cochée avec la date (sans aucune adresse).

## Pièges connus

- 12 est un **minimum** : un seul désistement recommence le décompte du testeur concerné ; 15 laisse de la marge.
- Un testeur doit s'inscrire avec le **même compte Google que celui du téléphone** où il installe.
- Un compte d'entreprise ou d'école (Workspace) peut empêcher l'installation : préférer un compte personnel (supposé, non vérifié).
- Ne pas promettre aux testeurs de recevoir quoi que ce soit ; ne pas leur demander de mot de passe ni de capture contenant leurs données.
