# DRV-713 — Spike Auchan A2 : anti-robot et connexion

- **Dépôt** : — (tâche **humaine** sur téléphone ; rapport `docs/drive/spikes/DRV-713-rapport.md`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-712 (savoir comment se choisit le magasin avant de naviguer plus loin)
- **Statut** : à faire.
- **Faisable sans téléphone** : **non, et jamais depuis un ordinateur ni un serveur**.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 11, 12 (inconnues U5, U6) ; [`../recherche.md`](../recherche.md) § 3 (DataDome chez Leclerc) ; [`../hypotheses-site-reel.md`](../hypotheses-site-reel.md) (H12, H13) ; [`../runbook.md`](../runbook.md) (diagnostic).

## Contexte

Les sources disent des choses contraires sur la protection d'Auchan : « pas d'anti-robot » (fiche d'un vendeur de collecte, non datée), page d'accueil servie en HTTP simple en août 2026, mais aucune source ne parle d'un compte connecté ni d'un panier. Chez Leclerc, DataDome a bloqué l'adresse de l'utilisateur et l'agent a dû apprendre à se mettre en pause devant un défi. La connexion d'Auchan passerait, d'après une source unique, par une chaîne OAuth à plusieurs étapes (A5).

## Règles de sécurité de l'essai

Mêmes règles que DRV-712 : téléphone, compte de l'utilisateur, à la main, aucune automatisation, aucune commande ; aucun cookie, jeton, adresse de retour, e-mail ni identifiant dans le dépôt. **Ne jamais répéter une action qui a déclenché un défi** : noter, attendre, s'arrêter.

## Portée (une ligne chacun)

1. **Défi visible** : pendant une dizaine de minutes d'usage normal (choisir un magasin, chercher trois produits, en mettre deux au panier, les retirer), une page de vérification (case à cocher, puzzle, « êtes-vous un robot ») est-elle apparue ? À quel geste ? Quel aspect (domaine seulement du défi) ?
2. **Présence d'un cookie anti-robot** : si le navigateur du téléphone le permet (écran « cookies » du site), un cookie dont le nom évoque un anti-robot est-il présent (oui/non et **famille du nom**, jamais la valeur) ?
3. **Réaction à la cadence** : après une série de 10 recherches espacées de 2 s (à la main), un message de ralentissement ? Noter le seuil si le site réagit, sinon « aucune réaction ».
4. **Connexion** : méthodes proposées (e-mail et mot de passe, Google, Apple, autre) ; la connexion redirige-t-elle vers un autre domaine (domaine seulement) puis revient ; le site garde-t-il la session après fermeture ; code de vérification par e-mail ou SMS ?
5. **WebView** : si la connexion propose Google ou Apple, noter que les connexions Google/Apple sont souvent **refusées dans une WebView** ; si e-mail et mot de passe sont possibles, la connexion est probablement faisable dans l'application (à confirmer par la recette de DRV-717, pas ici).
6. **Cookies et stockage** : le site exige-t-il l'acceptation de cookies avant d'afficher les prix (bandeau) ? Un bandeau couvre-t-il la page au point de gêner le clic « Commencer » ?
7. **Visiteur non connecté** : un visiteur peut-il remplir un panier sans se connecter ? (À noter : c'est le danger que l'agent de Leclerc détecte, ticket G.)

## Hors portée

Contourner quoi que ce soit ; mesurer précisément la cadence tolérée (elle se règle à la recette de l'adaptateur) ; les requêtes de recherche et de panier (DRV-714).

## Critères d'acceptation

- Le rapport répond à U5 (un anti-robot a-t-il été vu, oui/non/indéterminé, avec ce qui a été fait pour le voir) et U6 (méthodes de connexion et leur compatibilité probable avec une WebView).
- Chaque ligne indique « observé » ou « non observé » : **l'absence de défi en dix minutes n'est jamais présentée comme l'absence d'anti-robot.**

## Tests exigés

Aucun test de code ; relecture du rapport (fuites).

## Definition of Done

Rapport fusionné ; `multi-enseignes.md` § 11 mis à jour (A4 reclassé, U5 et U6 fermées ou reformulées) ; roadmap cochée.

## Pièges connus

- Un anti-robot peut réagir plus tard ou à d'autres gestes : conclure « non observé », pas « absent ».
- Ne pas copier l'adresse du défi complète : domaine seulement.
- La connexion se fait dans le navigateur du téléphone ; la WebView de l'application ne se teste qu'avec DRV-717.
