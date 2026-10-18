# DRV-410 — Écran « Mes produits » : priorités et produits refusés

- **Dépôt** : front, `src/features/drive/`
- **Jalon** : M4 Front
- **Taille** : M
- **Dépend de** : DRV-208, DRV-403
- **Statut** : fait (PR [repas-front#97](https://github.com/kcharignon/repas-front/pull/97), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 7, 9 ; `docs/adr/0003-design-system-basilic-et-safran.md` du front ; composants `src/design/Ui*` ; DRV-208.

## Contexte

Pour un même ingrédient, l'utilisateur peut accepter plusieurs produits classés par priorité (« la farine Marque Repère si elle est disponible, sinon une autre farine T45 ») et en refuser d'autres (« jamais la farine complète »). Cet écran lui permet de les gérer hors d'un envoi.

## Portée

Route `/drive/produits` (lien depuis `/drive/reglages`, DRV-403) :

1. Liste des ingrédients qui ont une préférence (`GET /drive/choices`), avec recherche par nom et état vide expliqué. Chaque ligne montre le produit préféré, le nombre de produits de secours et de produits refusés, ou « Tu en as toujours ».
2. Détail d'un ingrédient (page ou panneau plein écran sur téléphone) :
   - **Produits acceptés** dans l'ordre (« 1. … préféré », « 2. … secours »), chacun avec libellé et contenu ; boutons **« Monter »** et **« Descendre »** (pas de glisser-déposer obligatoire : accessibilité et téléphone), **« Retirer »**, **« Ne plus jamais prendre »** (le produit passe dans les refusés) ;
   - **Produits refusés** : liste avec **« Accepter de nouveau »** (le produit revient à la fin des acceptés, dans la limite de 5) ;
   - interrupteur **« J'en ai toujours »** (désactive les listes ; le réactiver n'invente aucun produit) ;
   - **« Oublier cet ingrédient »** (`DELETE`, confirmation).
   - Phrase d'aide permanente : « Repas prend le premier produit disponible de la liste. Les produits refusés ne sont jamais pris. »
3. Les modifications se font localement puis s'enregistrent d'un coup par **« Enregistrer »** (`PUT /drive/choices/{slug}`, DRV-208, avec les `productId` Repas) ; les erreurs `422 DRIVE_CHOICE_INVALID` (par exemple plus de 5 acceptés) sont affichées en clair ; « Annuler » rétablit l'état lu.
4. Hors ligne : lecture du dernier état en cache si disponible, actions désactivées. Aucun appel direct à Leclerc ; on ne peut pas **ajouter** un nouveau produit ici (il se choisit sur Leclerc pendant un envoi), seulement réorganiser, retirer, refuser et ré-accepter.
5. Textes dans `fr.ts`, composants `Ui*`, tutoiement, cibles ≥ 44 px.

## Hors portée

Choisir un nouveau produit (se fait sur Leclerc pendant un envoi, phase B), suggestions automatiques (DRV-601).

## Critères d'acceptation

- Pour la farine avec deux produits acceptés et un refusé : « Descendre » le préféré puis « Enregistrer » envoie `accepted` dans le nouvel ordre ; « Accepter de nouveau » le refusé l'ajoute en troisième ; la sixième tentative est refusée avec le message du back.
- « Ne plus jamais prendre » déplace le produit des acceptés vers les refusés sans le perdre.
- « J'en ai toujours » masque les listes et n'envoie ni produit accepté ni refusé.
- L'écran est utilisable à 360 px, au clavier et au lecteur d'écran (ordre annoncé « 1 sur 2 »).

## Tests exigés

Vitest (ordre, retrait, refus, ré-acceptation, limites, erreurs 422, annulation) ; e2e Pixel 7 ; accessibilité ; test visuel mobile et bureau.

## Definition of Done

Celle du front.

## Pièges connus

- Ne pas recalculer d'ordre côté serveur : le front envoie l'ordre voulu, le back valide.
- Un produit refusé et accepté dans la même demande est rejeté par le back : le front l'empêche aussi.

## Réalisation (2026-10-18, PR repas-front#97)

Routes `/drive/produits` (liste avec recherche) et `/drive/produits/:slug` (détail : monter, descendre, retirer, refuser, ré-accepter, « j'en ai toujours », oublier) ; modifications locales jusqu'à « Enregistrer » (un seul `PUT /drive/choices/{slug}`) ; entrée dans « Mon compte » si le drive est activé. À 5 produits acceptés, « Accepter de nouveau » est désactivé avec explication. Pas d'alerte en quittant avec des modifications non enregistrées.
