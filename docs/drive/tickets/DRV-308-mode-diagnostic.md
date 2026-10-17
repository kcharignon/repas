# DRV-308 — Mode diagnostic de l'agent

- **Dépôt** : front, `drive-agent/src/diagnostic/`
- **Jalon** : M3 Agent
- **Taille** : S
- **Dépend de** : DRV-303, DRV-304
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 (mode diagnostic) ; [`../recherche.md`](../recherche.md) § 9.

## Contexte

Quand Leclerc change son site, l'utilisateur doit pouvoir dire en un geste ce qui ne marche plus, sans rien exposer de personnel. Le diagnostic est aussi l'outil du support (DRV-503).

## Portée

1. Depuis l'écran d'accueil (« Diagnostic »), une série de contrôles en **lecture seule** : version de l'agent ; page prise en charge (hôte Leclerc, magasin lu) ; marqueur d'origine remplacé ; stockage de session disponible ; Wake Lock disponible ; une recherche réelle d'un terme courant (« sel ») : HTTP 200, nombre de produits analysés, présence des champs attendus ; analyse du contenu net sur les résultats ; cadre de même origine autorisé (création d'un `iframe` vers la page de recherche, détection de refus) ; aucune écriture au panier.
2. Chaque contrôle : `OK`, `KO` (avec code : `BLOCKED`, `CONTRACT_CHANGED`, `NOT_LOGGED_IN`, `UNSUPPORTED_PAGE`, `NETWORK`) ou `NON TESTÉ`.
3. Rapport texte copiable (bouton « Copier ») : version, date, résultats, **sans** cookie, identifiant de compte, adresse, nom, numéro de magasin complet (garder les trois derniers chiffres au plus) ni contenu de page.
4. Cadence respectée (une seule recherche), arrêt sur `BLOCKED`.

## Critères d'acceptation

- Sur le faux site (DRV-309) en fonctionnement normal, tous les contrôles sont `OK` ; en mode « contrat changé », « recherche » est `KO CONTRACT_CHANGED` ; en mode « 403 », `KO BLOCKED`.
- Le rapport copié ne contient aucune des valeurs interdites (test d'inclusion de motifs).

## Tests exigés

Vitest ; e2e contre le faux site (DRV-309).

## Definition of Done

Celle du front.
