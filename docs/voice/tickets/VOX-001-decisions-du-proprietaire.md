# VOX-001 — Décisions du propriétaire, ADR 0029 `Accepted`

- **Dépôt** : back (documentation)
- **Jalon** : M0
- **Taille** : S
- **Dépend de** : —
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 1, 2 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) tableau D1 à D6.

## Contexte

Un point change tout pour l'utilisateur : « Alexa, ajoute du pain à la liste » est impossible (liste d'Amazon fermée aux tiers). La phrase contient le nom du skill. Les autres décisions ont un défaut raisonnable.

## Portée

Poser **une question à la fois**, en français simple, avec le défaut recommandé (voir `feedback-drive-role-po-sans-code`) :

1. D1 le nom d'invocation (au moins deux mots ; défaut « mes courses ») ;
2. D4 qui s'en sert (défaut : son compte Amazon seulement) ;
3. D2, D3, D5, D6 : confirmer les défauts d'un seul message.

Reporter les réponses dans l'ADR 0029 (tableau, statut `Accepted`), dans `docs/adr/README.md` et dans cette roadmap.

## Critères d'acceptation

- L'ADR 0029 est `Accepted` avec les six décisions écrites ; le nom d'invocation figure dans `alexa.md` § 2 et dans les textes de VOX-204/205.

## Pièges connus

- Le nom doit être validé par la console Amazon (VOX-002) : prévoir un second choix.
- Ne pas retenir « liste de courses » dans le nom (phrase déjà interceptée par Amazon).
