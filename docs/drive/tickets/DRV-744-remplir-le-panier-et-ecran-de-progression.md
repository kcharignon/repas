# DRV-744 — « Remplir le panier » : le bouton d'envoi et l'écran de progression de l'agent

- **Dépôt** : front (`src/features/drive/`, `src/i18n/fr.ts`, `drive-agent/src/ui/`)
- **Jalon** : M7 Plusieurs enseignes (retour d'utilisateur, hors ordre)
- **Taille** : M (deux PR front)
- **Dépend de** : DRV-736, DRV-737 (le bouton nomme le drive), DRV-306 (l'écran de progression de l'agent)
- **Statut** : fait (PR front #280, bouton, fusionnée 2026-10-30 ; PR front #282, écran de progression, agent 0.53.0). À publier avec l'application Android qui embarque le nouvel agent. Aucun changement du protocole ni du back.
- **Lire d'abord** : [`../principes-produit.md`](../principes-produit.md) ; [`../consignes-agents.md`](../consignes-agents.md) ; `drive-agent/README.md` du front, « L'écran « Ton panier se remplit » ».

## Contexte

Retour du propriétaire (2026-10-30) : « quand j'envoie ma liste au drive et que je dois choisir entre Auchan et Leclerc, le bouton « Ouvrir Auchan / Ouvrir Leclerc » porte à confusion : le terme « ouvrir » n'est pas le bon, mets « Remplir panier Leclerc / Remplir panier Auchan » ; et la page qui s'affiche pendant que le panier se remplit est affreuse ».

Maquettes validées par le propriétaire (Claude Design, 2026-10-30) : planche 8 (le bouton) et planche 7 (« avant » et « proposition » de l'écran de progression).

## Portée

### PR 1 : le bouton du site (front #280)

1. Avec **plusieurs drives**, le bouton de l'écran d'envoi dit « **Remplir le panier {drive}** » (« Remplir le panier Leclerc », « Remplir le panier Auchan ») ; avec **un seul**, « **Remplir mon panier** » ; une petite icône de panier décorative (`aria-hidden`) devant le texte. Le nom vient du registre des enseignes : aucun `if` sur une enseigne. Touches : inchangées (avec un seul drive, le parcours est exactement celui d'avant).
2. Le texte d'attente (« Ouverture du drive… ») devient « **Je remplis ton panier…** » et l'échec du lancement « L'application n'a pas pu remplir ton panier. Rien n'a été mis au panier. Réessaie dans un instant. » : tout texte qui dit « ouvrir le drive » **pour lancer le remplissage** change.
3. **Ne changent pas** : les entrées qui ouvrent le site du drive pour naviguer (« Ouvrir le drive Leclerc » / « Ouvrir le drive Auchan » du menu natif de l'application) et « Rouvrir le drive » de l'attente du récapitulatif (il ramène à l'écran du drive).

### PR 2 : l'écran « Ton panier se remplit » de l'agent (front #282, agent 0.53.0)

1. Un **repère d'enseigne** en haut : pastille avec une icône de panier et « Panier {nom} » (nom pris dans la table de l'enseigne, aucun `if` sur une enseigne) ; absent du test automatique, qui a son propre titre.
2. Le titre en grand, puis la phrase de synthèse.
3. Une **carte d'avancement** : « 3 sur 6 » en grand, « articles traités », une vraie barre (`role="progressbar"`, `aria-label` « Avancement », `aria-valuemin/max/now`).
4. Une carte **« En ce moment »** (bordure verte) : un indicateur qui tourne et le nom de l'article ; en `prefers-reduced-motion`, un point fixe sans animation.
5. La **liste des articles traités** : une ligne par article, icône ronde (coche pleine : ajouté, ajouté en remplacement ; maison : « j'en ai toujours » ; trait : « pas cette fois » ; signe d'attention : à choisir ensuite, plus disponible, introuvable, pas pris par le panier, enregistré sans être au panier, lenteur, échec), nom en gras, état en petit à droite. Les plus récentes en bas, comme avant ; la liste défile et garde tout un envoi (100 lignes).
6. « Garde l'application ouverte. » avec une icône de téléphone, et « Arrêter » (52 px, contour) ; l'état « J'arrête après l'article en cours… » garde sa place.
7. **Le journal est structuré** (une sorte d'état et un nom, plus de phrases toutes faites) ; la phrase complète reste le **nom accessible** de chaque ligne (`aria-label`) ; ce que le résultat envoyé à Repas contient ne change pas d'un octet. L'écran reste un calque opaque, avec les mêmes boutons et le même titre ; sa palette est **sombre dans les deux thèmes**.

## Hors périmètre, suite possible

- Les **autres écrans de l'agent** (choix des produits, quantités, magasin, erreurs) gardent leur apparence : les harmoniser avec la palette et les gabarits de l'écran de progression est une suite possible.

## Critères d'acceptation

- L'écran d'envoi montre « Remplir le panier Leclerc » ou « Remplir le panier Auchan » quand deux drives sont configurés, « Remplir mon panier » sinon ; plus aucun « Ouvrir le drive » pour lancer le remplissage.
- Pendant le remplissage, on voit d'un coup d'œil où l'on en est (« 3 sur 6 » et la barre), ce qui se passe en ce moment, et ce qui est fait avec l'état de chaque article ; 360 × 640 sans défilement horizontal, contraste AA, cibles d'au moins 44 px, aucun emoji.
- Tests : états de ligne (icône, texte, `aria-label`), barre, mouvement réduit, arrêt (`test/ui.spec.ts`), un remplissage entier contre le faux site (`e2e/progression.spec.ts`) ; `make check`, `make agent-verify`, `make agent-e2e` et `make android-e2e` verts.
