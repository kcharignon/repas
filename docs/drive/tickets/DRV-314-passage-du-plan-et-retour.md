# DRV-314 — Passage du plan de Repas à l'application et retour

- **Dépôt** : front (`kcharignon/repas-front`), dossier `android/` (la partie Repas est dans DRV-402)
- **Jalon** : M3b Application Android
- **Taille** : L
- **Dépend de** : DRV-313, DRV-302
- **Statut** : à faire
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), [`../spikes/DRV-005-decision-g1.md`](../spikes/DRV-005-decision-g1.md), [`../spikes/DRV-011-rapport.md`](../spikes/DRV-011-rapport.md), `drive-agent/android-lab/` (le spike, `PROCEDURE.md` compris), `CLAUDE.md` du front, [`../architecture.md`](../architecture.md) §§ 8 et 9, DRV-402, DRV-405

## Contexte

L'utilisateur touche « Envoyer au drive » dans Repas (PWA). Repas doit lancer l'application avec le plan, l'agent travaille dans la WebView, puis l'utilisateur revient dans Repas avec le résultat. Le fragment d'adresse vers Leclerc n'est plus utilisé (D21 sans objet).

## Portée

1. **Aller** : l'application déclare un filtre d'intention pour le schéma `repasdrive://open` avec le paramètre `plan` (message `plan` du protocole v1 en base64url, 64 Ko au plus). Repas ouvre ce lien (lien d'intention Android `intent://` avec le nom du paquet, en repli le lien direct). L'application valide la forme (taille, base64url, `v`, `type`, `runId`) sans interpréter les lignes, garde le plan en mémoire, charge la page du magasin du plan (hôte autorisé seulement) et le transmet à l'agent (DRV-313). Si l'utilisateur n'est pas connecté, il se connecte, puis l'agent démarre.
2. **Retour** : à la réception du message `result` de l'agent (ou à l'abandon par l'utilisateur, résultat `aborted`), l'application ouvre `https://kantin.eu/drive/retour#result=<base64url>` par `ACTION_VIEW`. L'origine de retour est écrite en dur à la construction (`BuildConfig`), jamais lue d'un message.
3. **Reprise** : si l'application est fermée ou tuée pendant un passage, le dernier plan non terminé reste en mémoire persistante légère (identifiant d'exécution et plan, pas de cookie) pendant 24 h ; à la réouverture, l'application propose « Reprendre » ou « Abandonner » ; l'agent reprend avec son état de session (`runId`).
4. Un second plan reçu pendant un passage est refusé avec un message clair (un seul passage à la fois).
5. Les liens `repasdrive://` malformés ou trop gros sont ignorés avec un message en français.

## Hors portée

Le module `driveBridge` de Repas (DRV-402), l'écran de retour (DRV-405), la publication (DRV-315).

## Critères d'acceptation

- Étant donné la PWA Repas installée, quand l'utilisateur touche le lien, alors l'application s'ouvre et affiche le récapitulatif de l'agent (essai sur téléphone, ligne 11 du rapport DRV-002).
- Étant donné un passage terminé, quand le résultat arrive, alors Repas s'ouvre sur `/drive/retour#result=…` avec le même `runId`.
- Un plan de plus de 64 Ko ou d'un hôte non autorisé est refusé.

## Tests exigés

Tests Kotlin (analyse du lien, bornes, hôtes, reprise, refus d'un second plan) ; test de bout en bout contre le faux site Leclerc ; recette sur téléphone par l'utilisateur.

## Definition of Done

Celle du dépôt front ; PR concise ; fusion si CI verte.

## Pièges connus

- Un lien de même origine que la PWA est traité dans la PWA : d'où le schéma `repasdrive://` / `intent://`. À valider sur un vrai téléphone (si la PWA refuse de le lancer, l'alternative est un bouton « Ouvrir l'application » qui n'est pas dans la portée de la PWA, ou une adresse de pont sur un autre sous-domaine).
- N'importe quelle autre application peut déclarer le même schéma : le plan ne contient que des noms d'ingrédients, aucune donnée sensible.

## RÉVISION du 2026-10-18 (application Repas complète, D28)

Le passage du plan n'utilise plus de lien `repasdrive://` ni de fragment : le front Repas tourne dans la WebView de la coquille (DRV-317) et parle à l'application par le **pont natif** `repasNative`. `drive.start` (plan du protocole v1, 64 Ko au plus, validé) fait ouvrir `DriveActivity` sur la page du magasin du plan ; l'agent s'exécute ; à la réception du `result`, l'application poste `drive.result` à la WebView de Repas (mis en file si la page s'est rechargée) et revient à l'écran Repas. `drive.cancel` et l'abandon donnent un `result` `aborted`. Reprise 24 h après interruption, un seul passage à la fois, écran allumé seulement pendant un passage. Les critères sur le schéma `repasdrive://` et `https://kantin.eu/drive/retour#result=` ci-dessus sont remplacés par : `drive.start` suivi de `drive.result` dans la même application.

## Points laissés par DRV-312 et DRV-313 (2026-10-18)

- Le résultat arrive dans `AgentHost` (callback `onAgentMessage` avec `Inbound.FromAgent(type, text)`) ; `MainActivity` ne l'exploite aujourd'hui que dans la variante de débogage (`DebugRun`). L'envoi du plan passe par `AgentHost.send(texte)`, possible une fois la page annoncée (`ready`, après `hello`).
- Le lancement sur la page du magasin quand un plan est reçu reste à faire (`NavigationPolicy.START_URL` aujourd'hui).
- Restreindre l'écran allumé à la durée d'un passage.
- Le menu de débogage « Plan d'essai » peut réutiliser le chemin de réception d'un plan.
