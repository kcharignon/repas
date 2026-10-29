# 0022 — Remplir le panier d'un drive : agent de page, aucun identifiant d'enseigne

- **Statut** : Accepted (décision G1 du 2026-10-17, voir `docs/drive/spikes/DRV-005-decision-g1.md` ; véhicule de l'agent : application Android, voir la mise à jour en fin de document)
- **Date** : 2026-10-17
- **Liens** : [0001](0001-architecture-hexagonale.md), [0003](0003-cqrs-lecture-ecriture.md), [0007](0007-api-rest-v1.md), [0013](0013-agregats-references-par-identifiant.md), [0017](0017-deploiement-et-releases.md), [0021](0021-gestion-des-comptes-par-l-administrateur.md), [0023](0023-liste-envoyee-au-drive.md), [`docs/drive/`](../drive/README.md)

## Contexte

L'utilisateur veut, depuis un téléphone Android, remplir le panier de son drive (E.Leclerc d'abord) à partir de sa liste de courses, avec un produit mémorisé par ingrédient.

Faits ([`docs/drive/recherche.md`](../drive/recherche.md)) :

- E.Leclerc Drive n'a aucune API publique. Ses routes internes sont connues par rétro-ingénierie (recherche `recherche.aspx`, panier `panier.aspx?op=1` à quantité absolue).
- DataDome bloque les requêtes de serveur et le rejeu de cookies ; seuls des navigateurs réels dans la session de l'utilisateur fonctionnent.
- Chrome Android n'a pas d'extensions ; un favori JavaScript peut tourner dans la page.
- Notre back n'a ni worker ni tâche planifiée, aucun chiffrement au repos, un seul serveur de 1 Go ; les secrets sont des variables d'environnement.
- Les dépôts sont publics.

## Décision

1. **Le back ne contacte jamais une enseigne.** Aucune requête sortante vers leclercdrive.fr (ou autre) depuis `src/`.
2. **Aucun identifiant, cookie ni jeton d'enseigne ne transite par le back ou le front, ni n'est stocké ni journalisé.** L'utilisateur se connecte lui-même sur le site de l'enseigne. Garde-fou : aucun champ de ce type dans `docs/api/openapi.json`.
3. **Le remplissage est fait par un « agent de page »** : un script autonome, lancé par l'utilisateur (favori JavaScript sur Chrome), qui s'exécute dans la page de l'enseigne. Il ne contient aucun code distant : toute mise à jour passe par la réinstallation du favori.
4. **L'agent se limite à** lire le magasin courant, chercher un produit, fixer la quantité d'un produit au panier (quantité absolue), lire le produit que l'utilisateur ajoute dans le cadre de choix. Il ne choisit jamais de créneau, ne valide ni ne paie, ne lit ni cookies ni données de compte.
5. **Échanges Repas ↔ agent par fragment d'URL**, avec des messages versionnés (`v: 1`), validés strictement et plafonnés (≤ 100 lignes, quantités 1..99). L'origine de retour est écrite en dur dans le favori, jamais lue dans un message.
6. **Contexte `Drive` séparé.** `Drive` ne dépend que de `Shared` ; il référence les ingrédients par slug et l'utilisateur par identifiant ([0013](0013-agregats-references-par-identifiant.md)). Ce qui vient de `Repas` passe par un port `Shared`. Le contexte peut être supprimé d'un bloc si l'intégration disparaît.
7. **`Produit` est un fait public sans propriétaire ; le `Choix` (préférence) est privé** : pour un utilisateur, un magasin et un ingrédient, une liste **ordonnée** de produits acceptés (5 au plus ; le premier est préféré, les suivants servent de secours quand ceux qui précèdent sont indisponibles), une liste de produits **refusés** (20 au plus ; jamais proposés ni mis au panier), ou « j'en ai toujours ». Un produit n'est jamais à la fois accepté et refusé. La préférence est purgée avec le compte ([0021](0021-gestion-des-comptes-par-l-administrateur.md)) ; l'agent ne choisit jamais seul un produit absent de la liste.
8. **Les conditionnements (nombre de produits pour une quantité) se calculent dans le back**, dans `Drive` (`PackCalculator`), pas dans le front ni dans l'agent.
9. **Un envoi ne modifie jamais `checked`** et ne vide ni ne réduit le panier de l'enseigne.
10. **Interrupteur `DRIVE_ENABLED`**, coupé par défaut ; activé par l'humain après un déploiement stable.
11. **Aucun code produit avant la décision G1** : spikes sur le téléphone réel, lecture des CGU, relevé des en-têtes de sécurité.

## Conséquences

- Positives : aucune donnée d'identification à protéger ; pas de dépendance à un partenariat ; le mode assisté (liens de recherche) reste un repli ; les choix mémorisés servent aussi à d'autres enseignes plus tard.
- Négatives / coûts : l'intégration est non officielle et peut casser à chaque changement du site de l'enseigne ; un favori se réinstalle à chaque mise à jour ; un geste de plus pour l'utilisateur ; risque de blocage DataDome du compte ou de l'IP de l'utilisateur ; maintenance d'un agent en plus du back et du front ; les CGU de l'enseigne peuvent interdire l'usage (à lire avant G1).
- Garde-fous : Deptrac (`Drive` → `Shared` seulement) ; test du contrat OpenAPI ; revue de l'agent (pas de `eval`, pas de réseau hors du site de l'enseigne) ; faux site de test ; recette sur téléphone ; interrupteur.

## Alternatives écartées

- Serveur avec navigateur sans tête et identifiants stockés → DataDome, 2FA, fuite engageant notre responsabilité, un seul serveur sans coffre.
- Cookie de session envoyé au serveur → même blocage, vaut un identifiant.
- Application Android dédiée (WebView) → second projet, identifiants saisis dans notre appli.
- Navigateur distant diffusé → nous verrions la session.
- Leclerc dans un cadre intégré à Repas → refus possible, CSP, cookies de connexion.
- Sélecteur de produit dans Repas → un aller-retour par recherche, images bloquées par la CSP.
- Extension Chrome (n'existe pas sur Chrome Android), extension Firefox ou Safari (publication publique ou compte payant) → gardées pour le backlog.
- Code distant chargé par le favori → notre serveur deviendrait une porte vers les comptes de l'enseigne.
- Synchronisation du catalogue, scrapers payants, Open Food Facts → ne remplissent pas le panier ou extraction massive.
- Partenariat façon Jow → hors de portée en usage familial.

Détail complet : [`docs/drive/analyse.md`](../drive/analyse.md) § 4.

## Mise à jour du 2026-10-17 (soir)

Le moyen d'exécuter l'agent dans la page Leclerc change : Chrome Android ne lance aucun favori JavaScript sur le téléphone de l'utilisateur (essais DRV-006), qui a choisi une **application Android** (WebView limitée à `*.leclercdrive.fr`, agent embarqué dans l'application). Les invariants (aucun identifiant chez nous, le back ne contacte jamais l'enseigne, pas de code distant non vérifié) et le protocole v1 sont inchangés. Voir [`docs/drive/pivot-application-android.md`](../drive/pivot-application-android.md). L'option « Application Android dédiée (WebView) » listée plus haut comme écartée est donc **reprise**, après le spike DRV-011 ; la décision définitive est prise à G1.

## Décision G1 du 2026-10-17

Statut **Accepted**. Preuves et décisions : [`docs/drive/spikes/DRV-005-decision-g1.md`](../drive/spikes/DRV-005-decision-g1.md). Résumé : l'agent est embarqué dans une **application Android** (WebView limitée à `*.leclercdrive.fr`, Leclerc affiché en mode ordinateur) ; le choix du produit se fait dans la liste de l'agent (P2, le cadre natif est refusé par l'enseigne) ; le plan voyage de Repas vers l'application par un lien `repasdrive://open?plan=…`, le résultat revient par `https://kantin.eu/drive/retour#result=…` ; les invariants (aucun identifiant ni cookie d'enseigne chez nous, le back ne contacte jamais l'enseigne, agent embarqué et non chargé à distance) restent. Distribution par APK signé à installer à la main.

## Amendement du 2026-10-29 : le libellé et le prix sont propres à chaque compte

La décision 7 (« `Produit` est un fait public sans propriétaire ») reste vraie pour l'identité du produit, son code-barres, son contenu net, son rayon et sa famille chez l'enseigne. Le **libellé** et le **prix** qu'un compte affiche ou utilise (plan, « Mes produits », estimation du coût) sont ceux que **ce compte** a lui-même observés, gardés sur la ligne de son choix (`drive_choice_product.label`, `price_cents`, `price_seen_at`) ; la fiche partagée `drive_product` ne sert plus que de valeur de repli quand la ligne n'a pas la sienne. Voir l'[ADR 0032](0032-valeurs-propres-a-chaque-compte-pour-les-produits-du-drive.md).
