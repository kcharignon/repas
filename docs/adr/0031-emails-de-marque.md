# 0031 — E-mails de marque

- **Statut** : Accepted (2026-10-28, demande du propriétaire : « le mail est moche »)
- **Date** : 2026-10-28
- **Liens** : [0018](0018-extinction-de-twig.md), [0020](0020-confirmation-d-adresse-email.md), [0026](0026-mot-de-passe-oublie.md), `BrandedEmailLayout`, `BrandedEmailLayoutTest`

## Contexte

Les e-mails du back (bienvenue de l'inscription, « tu as déjà un compte », mot de passe oublié, confirmation d'adresse de l'ancien parcours) étaient du HTML minimal, presque du texte brut, écrit à la main dans chaque notifier. Twig est supprimé (ADR 0018). Une mise en page de marque doit être la même pour tous les messages et ne pas dépendre de ressources que les messageries bloquent.

## Décision

1. **Une mise en page partagée**, en PHP pur : `Shared\Infrastructure\Mailer\BrandedEmailLayout` reçoit un `BrandedEmailContent` (titre, accroche cachée de 80 à 100 caractères, paragraphes, bouton, durée du lien, lien secondaire facultatif, note, ligne du pied) et rend le HTML et le texte brut du même message (`compose()` assemble le `Email`). Les notifiers (`User\Infrastructure\Mailer`) disent le contenu et n'écrivent plus de HTML.
2. **HTML d'e-mail sûr** : tables de mise en page (`role="presentation"`), styles en ligne, 600 px au plus et fluide, `lang="fr"`, `viewport`, `color-scheme` clair et sombre avec `@media (prefers-color-scheme: dark)`, bouton à repli `bgcolor` (48 px de haut au moins, pleine largeur sur mobile), police système en repli (`'Figtree', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif`), charte « Basilic & safran » (vert `#1E6B4C` avec texte blanc, safran sans texte dessus), contraste AA (4,5:1) en clair et en sombre.
3. **Texte brut toujours présent** (même contenu, lisible) ; le lien du bouton est écrit en clair sous le bouton dans le HTML et seul sur sa ligne dans le texte (c'est ce que lisent les tests de flux).
4. **Aucune ressource externe sauf l'icône publique du site** (`{FRONT_URL}/icons/icon-192.png`, déjà servie par le front) : ni pixel, ni suivi, ni police ni feuille de style distantes. L'e-mail reste complet images bloquées : le bandeau est vert (`bgcolor`), le nom « Repas » est du texte, l'icône est décorative (`alt=""`, le nom est juste à côté : un lecteur d'écran ne lit pas « Repas » deux fois).
5. **Tout texte est échappé** (`htmlspecialchars`) ; un lien qui n'est pas une adresse `http(s)` sans espace, guillemet ni chevron est refusé (`InvalidArgumentException`).
6. **Voix** : celle du produit (`repas-po`) : tutoiement, phrases de 15 mots au plus, aucun point d'exclamation, aucun jargon, la durée du lien dite simplement. Les tests la vérifient sur le texte visible du HTML (`VisibleEmailText`) et sur le texte brut.
7. **Règle** : tout nouvel e-mail passe par `BrandedEmailLayout`, jamais de HTML écrit à la main dans un notifier.

Sans changement : objets, liens, routes, durées (24 h, 30 min), contrats d'API, comportement métier.

## Conséquences

- Positives : un seul endroit pour la marque, des messages lisibles sur téléphone, en sombre et sans images ; aucune requête de plus.
- Négatives / coûts : le HTML est plus long ; l'icône dépend de `FRONT_URL` (en développement, l'adresse locale n'est pas joignable des messageries : l'e-mail reste correct, sans icône) ; le rendu réel dépend du client de messagerie (Outlook ignore les coins arrondis, des clients inversent les couleurs de leur propre initiative).

## Alternatives écartées

- Twig ou le composant `twig-extra` d'e-mail : retiré par l'ADR 0018.
- Un logo envoyé en pièce jointe (CID) : alourdit chaque message et s'affiche mal selon les clients.
- Un logo en pur HTML/CSS (carré vert et lettre « R ») : possible, mais l'icône du site existe et reste celle que l'utilisateur reconnaît ; rien ne dépend d'elle.
