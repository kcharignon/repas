# DRV-403 — Réglages du drive (magasin, favori, produits mémorisés)

- **Dépôt** : front, `src/features/drive/`
- **Jalon** : M4 Front
- **Taille** : L
- **Dépend de** : DRV-203, DRV-204, DRV-307, DRV-402
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 7, 9 ; `docs/adr/0003-design-system-basilic-et-safran.md` du front ; composants `src/design/Ui*` ; `RecipeEditorView.vue` comme modèle d'écran.

## Contexte

Avant d'envoyer une liste, l'utilisateur a besoin de trois choses : connaître son magasin, installer le favori, et pouvoir gérer ses produits mémorisés.

## Portée

Route `/drive/reglages` (lien depuis les réglages du compte) :

1. **Magasin** : affiche le magasin enregistré (`GET /drive/settings`) ou « aucun » ; bouton « Enregistrer mon magasin Leclerc » qui construit un message `setup` (DRV-402), ouvre le site Leclerc et, au retour (`/drive/retour`, DRV-405), enregistre via `PUT /drive/settings`. Si `enabled` est faux : message « Cette fonction n'est pas encore activée » et rien d'autre.
2. **Favori** : étapes illustrées pour installer le favori sur Chrome Android ; bouton « Copier le code du favori » : télécharge `/drive/agent.template.js` (même origine), remplace `__REPAS_ORIGIN__` par `location.origin`, produit la chaîne `javascript:` (`buildBookmarklet`, DRV-301) et la copie dans le presse-papiers ; affiche la version du gabarit et son empreinte (manifeste) ; lien vers la page d'aide (DRV-408). Ne jamais rendre la chaîne `javascript:` comme `href` cliquable (CSP et sécurité) : texte copiable seulement.
3. **Produits mémorisés** : liste (`GET /drive/choices`) avec nom d'ingrédient, produit (libellé, contenu) et type ; actions « Oublier » (`DELETE`, confirmation) ; section « Ingrédients que j'ai toujours » avec « Réactiver » (supprime le choix) ; état vide expliqué. Pas de logique métier.
4. Hors ligne : lecture du dernier état en cache TanStack si disponible, actions désactivées.
5. Textes dans `fr.ts`, composants du design (`Ui*`), tutoiement, cibles ≥ 44 px.

## Hors portée

Changer un produit mémorisé en choisissant un autre (se fait lors d'un envoi : « Choisir un autre produit », DRV-404).

## Critères d'acceptation

- Un utilisateur sans magasin voit « aucun magasin » et peut lancer l'enregistrement ; avec un magasin, il voit son libellé ou son identifiant.
- « Copier le code du favori » place dans le presse-papiers une chaîne `javascript:` qui contient l'origine courante (et non le marqueur) ; le marqueur non remplacé n'est jamais copié.
- « Oublier » retire un produit après confirmation ; « Réactiver » retire un « j'en ai toujours ».
- L'écran est utilisable sur 360 px de large, sans défilement horizontal.

## Tests exigés

Vitest (`stubApi`, `appContext`) pour chaque section et chaque état (vide, erreur, interrupteur coupé) ; e2e Pixel 7 ; accessibilité ; test visuel mobile et bureau.

## Definition of Done

Celle du front.

## Pièges connus

- Le presse-papiers demande une action de l'utilisateur et un contexte sécurisé : prévoir un repli « sélectionner le texte ».
- Le service worker précache les ressources : le gabarit de l'agent est servi hors précache ou avec une stratégie qui reflète toujours la dernière version.
