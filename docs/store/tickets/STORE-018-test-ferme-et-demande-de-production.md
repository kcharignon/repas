# STORE-018 — Test fermé (12 testeurs, 14 jours) et demande d'accès à la production

- **Dépôt** : aucun (étape humaine, guidée par l'assistant)
- **Jalon** : M4 Essais et publication
- **Taille** : M (une heure de gestes, 14 jours d'attente)
- **Dépend de** : STORE-002 (au moins 15 testeurs), STORE-016 (bundle accepté, installation prouvée), STORE-017 (déclarations)
- **Faisable sans téléphone ni compte Play** : **non** (compte Play ; les testeurs ont des téléphones)
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 2, § 9 phase E et § 10 (chemin critique) ; [page Google « Exigences de test »](https://support.google.com/googleplay/android-developer/answer/14151465) ; skill `repas-ops`.

## Contexte

Avec un compte personnel créé après le 2023-11-13, Google exige un test fermé avec **au moins 12 testeurs inscrits en continu pendant 14 jours** avant de laisser demander la production. Les 14 jours sont **incompressibles** : plus tôt ce ticket démarre, plus tôt le public est servi. Il est donc le premier à lancer une fois STORE-016 et STORE-017 faits.

## Portée

Guider, une action par message, selon E.1 à E.8 de l'analyse (§ 9, phase E) :

1. Ouvrir la piste « Test fermé » ; créer la liste avec les **15 adresses** de STORE-002 (saisies par le propriétaire) ; publier **le même bundle** que le test interne (pas de nouveau code pour l'occasion).
2. Envoyer à chaque testeur le lien d'inscription et le message simple : « Ouvre le lien avec ton téléphone Android, touche Devenir testeur, puis Télécharger sur Google Play. Garde l'application deux semaines. » Relancer ceux qui n'ont pas installé.
3. Noter la date de début **le jour où la console affiche au moins 12 testeurs inscrits**. Ne retirer personne pendant 14 jours ; ne pas supprimer et recréer la piste.
4. Suivre le compteur de testeurs chaque semaine (deux fois suffisent) ; si le nombre tombe sous 12, recruter dans la marge de 3 et noter la nouvelle date de début.
5. Le 14ᵉ jour : *Tableau de bord > Demander l'accès à la production*, remplir les trois parties (test fermé : ce que tu as appris et changé ; application : public, fonctions ; prêt pour la production : ce qui est prêt). Réponses franches, sans promesse ; reprendre les éléments de l'annexe A et du compte de relecture.
6. Attendre la réponse de Google (délai non documenté). Si la demande est refusée, **lire la raison** à l'écran, la consigner, et ouvrir un ticket.

## Hors portée

La publication elle-même (STORE-019) ; l'achat de testeurs ou d'un service de « testeurs » tiers (écarté).

## Critères d'acceptation

- Étant donné la console, quand elle affiche le test fermé, alors au moins 12 testeurs sont inscrits depuis au moins 14 jours consécutifs.
- Étant donné le tableau de bord, quand le propriétaire envoie la demande, alors le formulaire est accepté et affiche « en cours d'examen ».
- Étant donné la réponse de Google, alors elle est consignée (date, accord ou raison de refus) dans la roadmap, sans donnée personnelle.

## Tests exigés

Aucun automatique. Preuve : comptage lu à l'écran, date de départ notée.

## Definition of Done

Accès à la production accordé (ou raison de refus consignée et ticket ouvert) ; roadmap cochée.

## Pièges connus

- Un testeur qui quitte puis revient **perd ses jours** ; garder de la marge.
- Les testeurs doivent utiliser le **même compte Google que celui du téléphone** où ils installent.
- Google pose des questions sur l'engagement et les retours des testeurs : prévoir quelques retours réels (même brefs) ; ne pas inventer.
- Ne pas modifier la clé de signature ni le nom de paquet pendant le test.
- Si une mise à jour est nécessaire pendant les 14 jours, la publier sur la même piste avec une étiquette plus grande : les testeurs ne sont pas désinscrits.
- Les capteurs de comportement de Google ne sont pas documentés : ne pas tenter de « simuler » des testeurs.
