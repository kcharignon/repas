# DRV-503 — Runbook : « Leclerc a changé »

- **Dépôt** : back (`docs/` et skill `repas-ops`)
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-501
- **Statut** : fait (PR [#151](https://github.com/kcharignon/repas/pull/151), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 8, 10 ; DRV-308 (diagnostic).

## Portée

Écrire `docs/drive/runbook.md` : symptômes (messages `CONTRACT_CHANGED`, `BLOCKED`, résultats vides), contrôle par le diagnostic, désactivation immédiate (`DRIVE_ENABLED=false` : les écrans disparaissent, les listes `SENT` restent lisibles et se terminent), repli en mode assisté, procédure pour corriger l'adaptateur (DRV-303) à partir d'un nouvel échantillon synthétique, reconstruire l'agent, relever la version, faire réinstaller le favori, vérifier l'empreinte, relever `MIN_AGENT_VERSION`, qui prévenir. Ajouter le renvoi dans le skill `repas-ops`.

## Critères d'acceptation

Une personne qui n'a pas écrit le code peut suivre le runbook pour couper la fonction puis publier une nouvelle version du favori.

## Réalisation (2026-10-18, PR #151)

- [`../runbook.md`](../runbook.md) : règles d'or, aide-mémoire, symptômes (titres des messages de l'agent et codes), diagnostic (diagnostic de l'agent en lecture seule ; test automatique de l'application de débogage, seul à éprouver l'écriture au panier), coupure par `DRIVE_ENABLED=false` avec le script de DRV-502, repli en mode assisté, message type pour la famille, correction (relevé fait par l'utilisateur sans cookie, adaptateur seul, `AGENT_VERSION`, empreinte `agent.js.sha384`, nouvelle application signée, version minimale, réinstallation), conditions de retour en service, interdits, journal des changements de Leclerc (vide).
- Renvois : skill `repas-ops` (`SKILL.md`, `references/incident.md`, `references/drive.md` section B), `CLAUDE.md`, `docs/drive/README.md`.
- **Adaptations au pivot Android** (le ticket parle encore du favori) : « faire réinstaller le favori » devient « installer la nouvelle version de l'application » ; « vérifier l'empreinte » = SHA-256 de l'APK et fenêtre « Version et mode d'emploi » (agent « intact »).
- **Écrit au conditionnel, à confirmer** : `MIN_AGENT_VERSION` (DRV-402) n'existe pas encore dans le code du front ; la publication par étiquette `android-vX.Y.Z` et l'alerte « nouvelle version » (DRV-315) non plus ; le mode assisté n'a pas encore de ticket détaillé. Le runbook demande de le vérifier. **Vérifié le 2026-10-18** : `MIN_AGENT_VERSION` n'existe pas et DRV-403 ne la prévoit plus (`runbook.md` § 7.5) ; la publication signée et l'alerte restent à faire (DRV-315) ; le mode assisté existe dans l'écran d'envoi, hors application et fonction allumée (`runbook.md` § 5).
- Point commun avec DRV-502 : fonction coupée, `reopen` répond aussi `404` ; les listes envoyées se terminent, se suppriment, ou se rouvrent en bloc par le script de remise en état. **Amendé le 2026-10-18 (DRV-613) : `reopen` répond fonction coupée ; chacun rouvre sa liste, le script en bloc ne sert plus qu'avant de remettre une version du code qui ne connaît pas `SENT`.**
