# STORE-012 — Bouton « Signaler » sur une recette d'un autre et mention des conditions à l'inscription

- **Dépôt** : front (`src/features/recipes/`, `src/views/RegisterView.vue`, `src/i18n/fr.ts`, tests)
- **Jalon** : M2 Conformité aux règles de Google Play
- **Taille** : S
- **Dépend de** : STORE-011 (contrat de l'API), [AUTH-010](../../auth/tickets/AUTH-010-conditions-d-utilisation-publiques.md) (page `/conditions` publique) ; **conditionnel** : seulement si D5 = B
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire (conditionnel)
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 6.f ; skills `repas-front-dev` et `repas-po` ; `docs/drive/principes-produit.md` (un écran, une décision ; erreurs en trois phrases).

## Contexte

Google demande que l'utilisateur **accepte des conditions avant de créer du contenu** et puisse **signaler** un contenu répréhensible depuis l'application. Il ne faut ni case à cocher en plus, ni fenêtre : la règle 4 de `repas-po` demande une confirmation par geste important, jamais deux.

## Portée

1. Sur l'écran d'une recette **qui n'est pas la sienne** (« Recettes des autres » puis une recette), une action discrète « Signaler cette recette » → une feuille avec une question « Pourquoi ? » et quatre choix (Contenu choquant ; Ce n'est pas une recette ; Publicité ou spam ; Autre) → un bouton « Envoyer le signalement ». Après `204` : « Merci. Un administrateur va regarder cette recette. » ; la recette reste visible. **Touches** : recette → Signaler → raison → Envoyer = 4 touches.
2. Erreurs en trois phrases : réseau → texte existant ; `429` → « Trop de signalements. Rien n'a été envoyé. Réessaie un peu plus tard. »
3. **Conditions à l'inscription** : sous le bouton de création du compte, une ligne discrète « En créant un compte, tu acceptes les conditions d'utilisation et la politique de confidentialité. » avec deux liens (`/conditions`, `/confidentialite`) ; **aucune case à cocher** ; la création vaut acceptation. La page des conditions gagne une courte section « Les recettes que tu partages » (ce qui est interdit : contenu choquant, spam ; qui regarde : l'administrateur ; ce qui arrive : la recette est retirée).
4. Liste d'administration des signalements : une page `/admin/signalements` (`ROLE_ADMIN`) avec la recette, la raison, le nombre, et un bouton « Retirer la recette » (confirmation unique) — alignée sur la page des utilisateurs existante.
5. Tests de glossaire et de voix mis à jour ; la route d'administration est dans la liste des routes réservées.

## Hors portée

Le blocage d'un utilisateur ; les notifications ; le back (STORE-011).

## Critères d'acceptation

- Étant donné la recette d'un autre, quand l'utilisateur touche Signaler, choisit une raison et envoie, alors `POST /api/v1/recipes/{id}/report` part et le message de remerciement s'affiche.
- Étant donné sa propre recette, alors le bouton n'apparaît pas.
- Étant donné l'écran d'inscription, alors la ligne et ses deux liens sont visibles sans défiler à 360 × 640 et **aucune case** n'est ajoutée ; le nombre de touches de l'inscription est inchangé.
- Étant donné un administrateur, quand il ouvre la page des signalements, alors il voit les recettes signalées et peut en retirer une ; un utilisateur ordinaire est renvoyé ailleurs.

## Tests exigés

Vitest (composants, route d'administration, textes) ; Playwright (parcours signaler, parcours administrateur) ; **captures lues** (360 px, clair et sombre) ; `make check`.

## Definition of Done

Celle du dépôt front ; touches avant / après dans « À relire » ; l'assistant coche la roadmap.

## Pièges connus

- Ne pas alourdir l'inscription : une ligne, pas une case (règle 2 et 4 de `repas-po`) ; la valeur « acceptation » repose sur l'action de créer un compte.
- La section « Les recettes que tu partages » est dans la **page des conditions**, pas sur l'écran de la recette (règle 10 : pas d'avertissement dans le parcours).
- Le bouton « Signaler » ne doit pas être le geste principal de l'écran.
