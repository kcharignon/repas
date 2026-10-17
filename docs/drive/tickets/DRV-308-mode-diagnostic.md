# DRV-308 — Mode diagnostic de l'agent

- **Dépôt** : front, `drive-agent/src/diagnostic/`
- **Jalon** : M3 Agent
- **Taille** : S
- **Dépend de** : DRV-303, DRV-304
- **Statut** : fait (PR [repas-front#83](https://github.com/kcharignon/repas-front/pull/83), 2026-10-18)
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

## Point laissé par DRV-304 et DRV-305 (2026-10-18)

L'écran d'accueil `welcome` est prêt (résultat `'diagnostic' | 'close'`) ; `agent.ts` ne l'appelle pas : c'est à ce ticket de le faire.

## Réalisation (2026-10-18, PR repas-front#83)

`drive-agent/src/diagnostic/{checks,report,index}.ts` : huit contrôles en lecture seule, une seule recherche (« sel », `TEST_TERM`), rapport texte copiable (numéro de magasin réduit à `…` plus trois derniers chiffres, hôte réduit à `fdN`). Le contrôle « cadre de même origine » est **`NON TESTÉ`** (P2 retenu, le site refuse les cadres) : DRV-309 doit tolérer ce `NON TESTÉ` dans « tous les contrôles OK ». Seuils : au moins la moitié des produits lus ont identifiant, libellé, prix et stock, et la moitié un contenu net lisible. Ouverture : l'agent exporte `DIAGNOSTIC_HASH` (`#repas-diagnostic`). **Décision (assistant, 2026-10-18)** : ne pas utiliser de fragment d'adresse sur les pages Leclerc (un fragment de 6 Ko a provoqué la page d'erreur de Leclerc, les fragments courts ne sont pas mesurés) ; l'application déclenche le diagnostic par un **message de commande** sur le pont (`{v:1, type:'command', command:'diagnostic'}`, validé par l'agent), à ajouter au protocole par DRV-313.
