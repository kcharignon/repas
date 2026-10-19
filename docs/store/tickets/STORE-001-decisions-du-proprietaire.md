# STORE-001 — Décisions du propriétaire et passage de l'ADR 0025 à `Accepted`

- **Dépôt** : back (documentation) ; assistant PO / architecte avec l'utilisateur
- **Jalon** : M0 Décisions, testeurs, compte
- **Taille** : S
- **Dépend de** : —
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non (mais D3 décide de la forme de STORE-005, qui, lui, bloque les étapes 11 à 13 d'AUTH-002)
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 0 et § 12 ; [ADR 0025](../../adr/0025-publication-sur-google-play.md) ; skill `repas-po` (une question précise, un défaut raisonnable) ; mémoire « Rôle sur le chantier » (l'utilisateur n'est pas anglophone natif).

## Contexte

Le cadrage recommande une réponse à cinq décisions. Aucune ne bloque la lecture de l'analyse ; D2 et D3 conditionnent les tickets humains (compte, clé), D4 la fiche, D5 deux tickets de code. Le compte est à payer et la clé à exporter : ce sont des gestes difficiles à défaire, donc posés avant eux.

## Portée

1. Présenter **une décision par message**, en français simple : la recommandation d'abord, ce que cela change pour les proches, l'alternative et son prix.
   - **D1** : tes proches installent d'abord depuis Google Play en test (tout de suite), puis publication pour tout le monde en France après le test de 14 jours (France seulement, 18 ans et plus) ?
   - **D2** : compte développeur **personnel** (25 USD, tu recrutes 12 testeurs) avec une **adresse d'assistance dédiée** ? Ton nom légal et ton pays pourraient s'afficher sur la fiche. Quelle adresse d'assistance (alias sur ton domaine ou adresse dédiée chez un fournisseur) ?
   - **D3** : importer la clé actuelle dans Google Play, et garder l'APK GitHub deux versions avant de le retirer ?
   - **D4** : citer « E.Leclerc » une seule fois dans la description, avec « ni affilié, ni approuvé », et garder l'écran du drive dans la version du store ?
   - **D5** : signaler une recette d'un autre (option B) ; ou cacher « Recettes des autres » dans la version publique (A) ; ou décider plus tard (C) ?
2. Noter chaque réponse (date, une ligne) dans un tableau « Décisions du propriétaire » ajouté à l'ADR 0025, et dans `docs/store/publication-play-store.md` § 12.
3. Passer l'ADR 0025 à `Accepted` (statut, date) et sa ligne de `docs/adr/README.md`.
4. Ajuster les tickets et la roadmap si une réponse diffère de la recommandation, selon ce tableau :

| Réponse | À modifier |
|---|---|
| D1 « test seulement, jamais public » | STORE-017 à STORE-019 abandonnés ; STORE-002 reste utile seulement pour la famille ; la conformité (008 à 013) n'est plus exigée par Google mais reste bonne à faire |
| D2 « organisation » | STORE-002 sans objet (pas de test fermé) ; STORE-003 change (D-U-N-S, jusqu'à 30 jours) ; STORE-018 réduit à la demande d'examen |
| D3 « clé de Google » | STORE-005 prend la variante B (empreintes de la console) ; STORE-007 devient obligatoire ; AUTH-002 (11 à 13) attend ces empreintes ; STORE-020 retire l'APK GitHub tout de suite ; réinstallation sur chaque téléphone |
| D4 « ne pas nommer l'enseigne » | STORE-014 (texte de la description) ; STORE-022 reste conditionnel |
| D4 « variante sans drive dès le départ » | STORE-022 passe de conditionnel à obligatoire avant STORE-016 |
| D5 « A » | STORE-011 et STORE-012 supprimés ; nouveau ticket front « masquer Recettes des autres dans l'application publique » |
| D5 « C » | STORE-011 et STORE-012 reportés, mais à faire **avant** STORE-019 |

## Hors portée

Tout code. Le paiement du compte (STORE-003). Les décisions sur les conditions d'utilisation de l'enseigne (clos).

## Critères d'acceptation

- Étant donné les cinq réponses, quand on ouvre l'ADR 0025, alors son statut est `Accepted`, le tableau des décisions est rempli et daté, et l'adresse d'assistance choisie est **décrite** (pas écrite) : « alias de mon domaine » ou « adresse dédiée », jamais l'adresse elle-même.
- Étant donné une réponse différente de la recommandation, quand on relit la roadmap, alors les tickets du tableau sont corrigés dans la même PR.

## Tests exigés

Aucun (documentation). Relire les liens relatifs.

## Definition of Done

PR de documentation verte, commits courts en français sans `Co-Authored-By`, `CLAUDE.md` inchangé (la ligne du chantier existe déjà).

## Pièges connus

- Ne pas poser les cinq questions d'un coup : l'utilisateur répond mal à une liste.
- Dire clairement, avant D2, que le nom légal et le pays peuvent apparaître sur la fiche (deux pages d'aide de Google se contredisent) : c'est un fait que le propriétaire doit connaître avant de payer.
- Ne jamais écrire d'adresse e-mail, de nom ni de numéro dans le dépôt.
