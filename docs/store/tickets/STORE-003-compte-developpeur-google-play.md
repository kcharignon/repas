# STORE-003 — Compte développeur Google Play : inscription, paiement, vérifications

- **Dépôt** : aucun (étape humaine, guidée par l'assistant)
- **Jalon** : M0 Décisions, testeurs, compte
- **Taille** : M (une heure d'étapes courtes, puis une attente de quelques jours)
- **Dépend de** : STORE-001 (D1, D2)
- **Faisable sans téléphone ni compte Play** : **non** : il faut un vrai téléphone Android (10 ou plus, non rooté) pour la vérification de l'appareil
- **Bloquant pour AUTH-002 / la connexion Google** : indirectement (STORE-005 en dépend)
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 3 et § 9 phase A ; skill `repas-ops` (une action par message, but expliqué, attendre le résultat, jamais de secret dans le chat).

## Contexte

Le compte est le seul prérequis de tout le reste (clé, test interne, test fermé). C'est aussi ce qui demande le plus d'attente (vérification du paiement jusqu'à 5 jours, délai d'identité non documenté). Il se paie 25 USD une seule fois, par carte (pas de carte prépayée) ; les frais ne sont pas remboursés si Google juge les informations invalides.

## Portée

Guider l'utilisateur, **une action par message**, selon les étapes A.1 à A.13 de l’analyse (§ 9, phase A). Prérequis à vérifier avant A.1 :

1. **Adresse d'assistance dédiée** (décision D2) : un alias de redirection chez le fournisseur du domaine, ou une adresse créée pour le service. C'est l'« e-mail du développeur », **publique** sur la fiche. Jamais l'adresse personnelle.
2. Le compte Google qui possédera le compte développeur a la **validation en deux étapes**.
3. Un téléphone Android avec l'application « Play Console » installable, une carte de paiement, une pièce d'identité.
4. Le **nom de développeur** public choisi (modifiable plus tard) : il ne contient ni le nom d'une enseigne, ni « Google », ni « officiel ».

Dire **avant le paiement** : « d'après l'aide de Google, ton nom légal et ton pays peuvent s'afficher sur la fiche ; l'adresse complète seulement si tu vends. » Puis A.1 à A.13 ; à A.12, attendre le message de Google ; à A.13, ajouter un second propriétaire (recommandé).

## Hors portée

La création de l'application et de la clé (STORE-005) ; la fiche ; la déclaration « commerçant / non-commerçant » du règlement européen si la console la demande (le propriétaire répond seul : l'assistant ne tranche pas, il dit seulement que l'application est gratuite et sans publicité).

## Critères d'acceptation

- Étant donné le tableau de bord de la console, quand le propriétaire l'ouvre, alors aucune tâche de vérification (identité, appareil, paiement) n'est en attente.
- Étant donné la page « Utilisateurs et autorisations », alors elle liste deux propriétaires (si le second a été invité).
- Étant donné la page « Compte développeur », alors le nom de développeur et l'e-mail affichés sont ceux choisis (alias dédié).
- Aucun mot de passe, code reçu ni numéro de carte n'a été écrit dans le chat ou le dépôt.

## Tests exigés

Aucun automatique. Preuve : le propriétaire dit « fait » à chaque étape ; l'assistant note la date de fin dans la roadmap, sans aucune valeur.

## Definition of Done

Compte validé ; reçu du paiement conservé par le propriétaire ; roadmap cochée avec la date.

## Pièges connus

- Les pages d'aide de Google se contredisent sur ce que le compte personnel montre publiquement : partir du principe que **nom légal et pays** s'affichent.
- Prépayée refusée ; une carte au nom d'un autre que le titulaire de la pièce d'identité peut faire échouer la vérification (les documents doivent correspondre exactement au profil de paiement).
- Ne pas passer par un site tiers qui promet d'« accélérer » l'inscription.
- L'e-mail du développeur est public : y arriveront des messages non sollicités ; prévoir un tri.
- D’après des pages d’aide lues par résultat de recherche seulement, un compte personnel peut devenir un compte organisation mais pas l’inverse : choisir avec soin.
- Ne pas ouvrir l'application ni publier quoi que ce soit avant STORE-005 (la clé de signature se choisit avant tout bundle).
