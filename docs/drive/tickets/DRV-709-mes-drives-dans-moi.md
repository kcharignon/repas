# DRV-709 — « Mes drives » dans « Moi » : liste, ajout, retrait, drive habituel

- **Dépôt** : front (web : `src/features/drive/DriveSettingsView.vue`, `queries.ts`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-703, DRV-708
- **Statut** : à faire. Effort recommandé : `high`.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 4.4, 5 ; [`../principes-produit.md`](../principes-produit.md) (règles 1, 2, 3, 6, 10 ; glossaire) ; skills `repas-front-dev` et `repas-po` ; DRV-403, 622, 623 (un seul geste pour le magasin).

## Contexte

L'écran des réglages du drive montre un magasin et un bouton qui le lit dans l'application. Avec plusieurs enseignes activées il doit montrer la liste des drives de la personne, permettre d'en ajouter ou d'en retirer un, et de choisir son **drive habituel**. **Avec une seule enseigne activée, l'écran reste exactement celui d'aujourd'hui.**

## Portée

1. Si `retailers` (de `GET /drive/settings`) ne contient qu'**une** enseigne : écran inchangé, aucun mot nouveau.
2. Sinon : un bloc « Mes drives » qui liste chaque enseigne activée : son nom, « Ton magasin : …{3 derniers chiffres} » si elle est configurée, le bouton « Choisir mon magasin {nom} » sinon (même geste que DRV-623 : un seul bouton qui lit le magasin dans l'application), « Changer de magasin » et « Retirer {nom} » (confirmation, car difficile à défaire : les choix mémorisés restent, la liste le dit en une phrase).
3. « Mon drive habituel » : un choix parmi les enseignes **configurées** (masqué s'il y en a une seule ; « Aucun » possible), écrit par `PUT /drive/default-retailer`.
4. Les appels d'API prennent l'enseigne de la ligne touchée (jamais un littéral) ; `DELETE /drive/settings?retailer=` pour « Retirer ».
5. La lecture du magasin envoie `drive.setup` **pour l'enseigne touchée** ; l'application doit l'annoncer dans `app.info.retailers` (sinon la phrase « Mets à jour l'application pour ajouter {nom}. » remplace le bouton).
6. Mot à ajouter au glossaire de `principes-produit.md` (dans la PR du back qui accompagne, ou rapporté à l'assistant) : « **Mon drive habituel** » ; interdits : « par défaut », « préféré », « enseigne ».

## Hors portée

Le choix à l'envoi (DRV-710) ; l'interrupteur par enseigne dans l'interface ; la comparaison de prix entre enseignes.

## Critères d'acceptation

- Étant donné une seule enseigne activée, alors **captures identiques** à celles d'avant (mobile 360 px et bureau), même nombre de touches.
- Étant donné deux enseignes activées dont une configurée, alors l'écran montre « Mes drives », la configurée avec son magasin et l'autre avec « Choisir mon magasin {nom} » ; pas de choix de drive habituel.
- Étant donné deux configurées, alors « Mon drive habituel » apparaît ; le choisir écrit `PUT /drive/default-retailer` ; « Aucun » écrit `null`.
- Étant donné « Retirer », alors confirmation, `DELETE`, et le drive disparaît de la liste ; si c'était le drive habituel, celui-ci s'efface.
- Aucun texte ne contient « enseigne » ; tous au tutoiement, phrases de 15 mots au plus.

## Tests exigés

Vitest (vue, états : une enseigne / deux dont une configurée / deux configurées / retrait / application trop ancienne pour une enseigne), Playwright (Pixel 7 et bureau) avec un faux back à deux enseignes et un pont natif simulé, test du glossaire, **captures lues** à 360 px en clair et en sombre.

## Definition of Done

Celle du dépôt front ; parcours (touches avant/après) et captures dans « À relire » ; types de l'API régénérés ; `CLAUDE.md` du front à jour.

## Pièges connus

- « Retirer » ne supprime ni les choix ni les produits (faits et préférences conservés pour un retour) : ne pas le promettre autrement.
- Ne pas ajouter de décision à l'écran à une seule enseigne (règle 1).
- La lecture du magasin dure plusieurs minutes (connexion, choix) : l'état « en cours » est par enseigne.
