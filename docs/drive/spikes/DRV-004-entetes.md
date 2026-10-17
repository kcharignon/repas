# DRV-004 — Rapport : en-têtes de sécurité de leclercdrive.fr

Gabarit à remplir par l'utilisateur avec les outils de développement d'un navigateur de bureau (F12, onglet Réseau, cliquer la requête du document, onglet « En-têtes », partie « En-têtes de réponse »). Ne copier **que** les en-têtes listés ci-dessous. **Ne jamais copier** `Cookie`, `Set-Cookie`, `Authorization` ni quoi que ce soit lié à la connexion.

Pages à relever (connecté ou non, magasin choisi) : (1) la page d'accueil du magasin, (2) une page de résultats de recherche (`recherche.aspx?TexteRecherche=farine`), (3) la page qui montre le panier ou le récapitulatif de commande.

**Première passe, 2026-10-17 : page d'accueil seulement.** La recherche et le panier restent à relever.

| En-tête | Accueil | Recherche | Panier |
|---|---|---|---|
| `Content-Security-Policy` (texte complet) | **absent** | à relever | à relever |
| `Content-Security-Policy-Report-Only` | absent | à relever | à relever |
| `X-Frame-Options` | `DENY` | à relever | à relever |
| `Cross-Origin-Opener-Policy` | absent | à relever | à relever |
| `Cross-Origin-Embedder-Policy` | absent | à relever | à relever |
| `Referrer-Policy` | absent | à relever | à relever |
| `Permissions-Policy` | absent | à relever | à relever |

- Date et navigateur utilisés : 2026-10-17, Chrome 155 (ordinateur, Windows)
- La page de recherche se charge-t-elle sans appel XHR de recherche (le JSON des produits est-il dans le HTML) ? Observation : oui, le JSON des produits est dans le HTML de `recherche.aspx` (banc d'essai DRV-002, ligne 4).
- Adresse (sans identifiants) de la page panier ou récapitulatif : à relever

Autres en-têtes de réponse vus sur la page d'accueil (utiles) : `X-DataDome: protected`, `Accept-CH` (demande des indications sur l'appareil : Sec-CH-UA, plateforme, modèle, mémoire), `Strict-Transport-Security: max-age=63072000; includeSubDomains`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-cache, no-store, must-revalidate`. Même absence de politique de sécurité sur les réponses d'écriture du panier (DRV-007), qui portent aussi `X-Frame-Options: DENY`. Aucune politique de sécurité dans une balise `<meta>` (sonde du banc d'essai).

## Conclusion (pour DRV-005) — provisoire, accueil seulement

- Script en ligne autorisé (`'unsafe-inline'` ou nonce) : **oui** (aucune politique de sécurité sur la page d'accueil)
- `connect-src` autorise-t-il notre origine : **oui** (aucune politique) ; le serveur de Repas devra, lui, autoriser l'origine de Leclerc (CORS) si un script de Leclerc l'appelle
- Cadre de même origine possible (`X-Frame-Options`, `frame-ancestors`) : **non** (`X-Frame-Options: DENY`, confirmé par le refus du cadre au banc d'essai)
- COOP présent : **non** sur l'accueil. Un onglet Leclerc ouvert par Repas avec `window.open` (sans `noopener`) garde donc un lien vers l'onglet Repas (`window.opener`), à confirmer sur la page de recherche et sur téléphone.
- Conséquence pour D20 : rien, côté en-têtes, n'empêche un favori chargeur qui charge un script depuis l'origine de Repas. Reste à connaître la taille de favori installable (DRV-006).
