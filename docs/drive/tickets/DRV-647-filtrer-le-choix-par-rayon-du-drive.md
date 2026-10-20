# DRV-647 — Filtrer le choix du produit par rayon du drive

- **Dépôt** : front (`drive-agent/`), web seulement si un libellé de rayon doit voyager
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M (deux étapes : mesure, puis écran)
- **Dépend de** : DRV-642 lot 1 (fait), DRV-646 (fait)
- **Statut** : fait, recette en cours (PR front #169, #172, #174 à #178, 2026-10-20 ; idée de l’utilisateur du 2026-10-20). Il réalise le lot 2 « filtre du site » de DRV-642
- **Lire d'abord** : [`DRV-642`](DRV-642-chercher-dans-le-bon-rayon.md) (« Mesure réelle des rayons »), [`DRV-646`](DRV-646-diagnostic-des-rayons.md), `docs/drive/multi-enseignes.md` et la règle « Ne pas durcir Leclerc » de `docs/drive/consignes-agents.md`, skill `repas-agent-dev`, skill `repas-po`.

## Contexte

L'utilisateur, 2026-10-20 : « Il faut peut-être pouvoir, dans la recherche, quand on doit sélectionner le bon produit, jouer sur les filtres du drive pour forcer un rayon existant du drive, sans forcément de corrélation avec le rayon dans Repas. »

DRV-642 classe déjà les candidats par rayon **appris** des choix précédents, mais il faut un premier choix pour apprendre, et un classement automatique ne remplace pas la main de la personne : pour « courgette », les produits transformés (purées, conserves, plats bébé) passent devant les légumes frais, et un seul produit sur trois est dans le bon rayon (mesure du 2026-10-19). Le rayon du site est le bon critère : il n'a rien à voir avec le rayon de Repas.

## Portée

1. **Étape 0, mesure (agent 0.32.0, diagnostic seulement)** : le « Diagnostic de l'agent » ajoute ce qu'il faut pour savoir **d'où viennent les libellés de rayons** (le JSON de recherche ne porte que `iIdRayon` / `iIdFamille`) : (a) les NOMS des clés du premier niveau de la réponse de recherche et d'un produit (jamais les valeurs, sauf les identifiants de rayon et famille), pour voir si une liste de filtres (facettes) existe ; (b) les liens du menu « Rayons » de la page du magasin quand ils sont dans le DOM : identifiant et libellé de rayon (texte de catalogue public, au plus 40 lignes) ; (c) la correspondance éventuelle entre ces identifiants et ceux des produits vus. Aucune donnée personnelle, aucun cookie, aucun jeton. Publication : agent 0.32.0 par la voie habituelle ; l'assistant lit le rapport sur le téléphone et fige la source des libellés avant l'étape 1.
2. **Étape 1, écran (après mesure)** : dans le choix du produit, une rangée de « rayons » au-dessus des résultats : « Tous » (par défaut) puis les rayons réellement présents dans les résultats, avec leur nombre, du plus fourni au moins fourni, libellé du site (repli : « Rayon » suivi d'un numéro si aucun libellé n'existe, jamais un identifiant nu sans mot). Toucher un rayon n'affiche que ses produits, **sans nouvelle requête** (les candidats sont déjà là) ; retoucher le rayon ou « Tous » annule. Le classement DRV-642 (rayon appris en premier) reste la valeur de départ : si un rayon est appris, il est présélectionné et « Tous » reste à un toucher.
3. **Aucun lien avec les rayons de Repas** : le filtre ne regarde que les rayons du drive. Le rayon du produit choisi continue d'être mémorisé comme aujourd'hui (`rayonId` / `familyId`, lot 1) : choisir un produit après avoir filtré **apprend** le rayon pour la prochaine liste.
4. Une seule décision par écran, aucun avertissement (règles 3 et 10 de `principes-produit.md`) : pas de texte d'explication long, pas de fenêtre.
5. **Ne pas durcir Leclerc** : la rangée de filtres est générique (un tableau de `{id, label, count}` fourni par l'adaptateur de l'enseigne) ; la lecture des libellés appartient à l'adaptateur Leclerc.

## Hors portée

Filtre par marque, par prix, par famille ou sous-famille ; recherche DANS un rayon par une requête du site (lot 2 de DRV-642, à décider après cette mesure) ; toute modification du back ; toute modification du protocole v1 (aucune clé nouvelle n'est attendue).

## Critères d'acceptation

- Étant donné un mot dont les résultats couvrent plusieurs rayons, quand on ouvre le choix, alors la rangée montre « Tous » et les rayons présents avec leur nombre, et toucher un rayon ne laisse que ses produits sans appel réseau.
- Étant donné un rayon appris pour ce rayon de Repas, alors il est présélectionné et « Tous » le désélectionne.
- Étant donné qu'aucun libellé de rayon n'est lisible, alors la rangée reste utilisable avec un libellé de repli lisible (pas un identifiant brut).
- Étant donné un produit choisi après un filtre, alors son `rayonId` est dans le résultat comme avant (aucun changement de protocole).
- Le diagnostic ne contient aucune donnée personnelle.

## Tests exigés

Agent : tests unitaires du calcul des rayons présents (comptes, tri, repli de libellé) et du filtre, test de bout en bout sur le faux site Leclerc (ajouter au faux site des produits de plusieurs rayons, avec et sans libellés), captures à 360 px lues avant de fusionner. Le faux site ne prouve pas le vrai site : la recette se fait sur le téléphone de test.

## Definition of Done

Celle du dépôt front et de l'agent (lint, tests, e2e de l'agent, `android/agent.js.sha384` à jour, version de l'agent relevée) ; application publiée avec le nouvel agent ; recette sur le téléphone de test avec « courgette » et « tomate ».

## Réalisation

- **Étape 0 (fait)** : PR front #169 (agent 0.32.0). Mesure du 2026-10-20 sur le téléphone de test : le menu « Rayons » de la page ne donne aucun libellé (10 liens « Voir le rayon », adresses `/magasin-N-N-V/rayon-N-….aspx ?Filtres`, aucun bloc de menu ni lien de famille) ; chaque produit porte `sLibelleUrlNaviguerRayon`, `sUrlVoirRayon`, `sCategorie`, `sIdUniqueRayon`, `fVoirRayon` (valeurs non lues à ce stade) ; la réponse porte `objFiltreBloc` (135) et la page `objNavigationRayonFamille` ; chemin des produits `objContenu > lstElements[] > objElement` (73 clés par produit). Répartition mesurée pour « tomate » : 130 produits dans un rayon, 11 dans le rayon des légumes frais probables : le rayon utile est minoritaire.
- **Étape 1 (PR front #172, agent 0.33.0)** : rangée « Tous (N) » puis les rayons présents avec leur nombre, au-dessus des résultats du choix ; toucher un rayon ne laisse que ses produits, sans requête ; le rayon appris est choisi d’avance et placé juste après « Tous » ; moins de deux rayons : pas de rangée ; protocole inchangé. Libellé résolu par l’adaptateur, dans l’ordre : `sLibelleUrlNaviguerRayon` lisible → nom dans `sUrlVoirRayon` → `sCategorie` → objet de rayon hors des produits (réponse, puis page) → « Rayon N ». On garde le texte le plus fréquent ; on écarte un nombre long, une adresse électronique ou web, ou une ville. Le diagnostic donne, pour les 8 rayons les plus fournis de « tomate », le libellé et sa source, les valeurs sûres des trois champs du premier produit, et les noms des clés de `objFiltreBloc` et `objNavigationRayonFamille`.
- **Suite des PR front (2026-10-20)** :
  - #169 étape 0 (agent 0.32.0) ; #172 étape 1, filtre par famille (0.33.0) ; #174 libellés réels, jamais « Voir le rayon » (0.34.0) ; #175 diagnostic de la facette (0.35.0) ; #176 filtre par la facette « Rayons » (0.36.0) ; #177 filtre du site validé par cohérence avant d'afficher ses rayons (0.37.0) ; #178 toutes les listes de produits lues et rayon touché contrôlé (0.38.0, cause racine de DRV-642 : voir son ticket). Applications 0.1.17 à 0.1.22 ; **seules 0.1.18 (familles) et 0.1.22 (cause racine) sont à recommander**, 0.1.19 à 0.1.21 sont des intermédiaires.
  - **Source réelle des libellés** : pour les **familles**, `sUrlVoirRayon` (l'identifiant du lien est celui de la famille, le nom est lisible) ; pour les **rayons du site**, la facette « Rayons » de la réponse (`iIdBloc` = 4 : `sLibelle`, `iIdValeur`, `niNbReferences`, `sIdValeurUrl`, `lstEnfants`). `sLibelleUrlNaviguerRayon` vaut toujours « Voir le rayon » : inutilisable. Détail : [`../recherche.md`](../recherche.md) § 10.
  - **Format du filtre** : `recherche.aspx?TexteRecherche=<mot>&Filtres=<sIdValeurUrl>` (forme `N-N`).
  - **Validation par cohérence** : une forme de filtre n'est retenue que si le nombre de produits est proche de `niNbReferences` et/ou si les produits sont dans les enfants de la valeur ; jamais sur « moins de produits » (un essai avec « Fruits Légumes » avait renvoyé des produits incohérents avant la correction de lecture de #178).
- **Reste à faire** : usage réel de la rangée des rayons du site sur le téléphone de test (« courgette », « tomate »), et installer l'application 0.1.22 (seules 0.1.18 et 0.1.22 sont à recommander).
