# DRV-627 — Passer toute la voix de Repas au tutoiement

- **Dépôt** : front (`src/i18n/fr.ts`, `drive-agent/src/ui/strings.ts`, `android/`), à vérifier côté back (e-mails)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M (balayage mécanique, volumineux)
- **Dépend de** : DRV-628 (pour ne réécrire qu'une fois les textes restants)
- **Statut** : à faire plus tard (décision de l'utilisateur du 2026-10-19 : « Je préfère le tutoiement »)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (§ 3), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`, `repas-android-dev`.

## Contexte

DRV-621 avait fixé le vouvoiement (décision réversible du PO). L'utilisateur préfère le tutoiement : la décision s'inverse. Toute l'application Repas, pas seulement le drive, parle de la même voix.

## Portée

1. Tous les textes visibles : `fr.ts` (web), `strings.ts` (agent), textes de l'application Android, messages d'erreur affichés, page d'aide et conditions d'utilisation.
2. Impératifs : « Choisissez » → « Choisis », « Ouvrez » → « Ouvre », « Touchez » → « Touche ». Possessifs : « votre » → « ton / ta / tes », « vos » → « tes ». Pronom : « vous » → « tu ».
3. Le test de voix `voice-and-glossary.spec.ts` s'inverse : il interdit « vous », « votre », « vos », « -ez » à l'impératif dans les textes de Repas (sauf citations de Leclerc), et accepte le tutoiement.
4. Accord des phrases courtes : toujours 15 mots au plus.
5. À vérifier côté back : e-mails de vérification d'adresse, messages de l'API que le front affiche tels quels.
6. Les pages de Leclerc que l'agent recouvre restent au vouvoiement de Leclerc : la voix de l'agent change quand même (c'est Repas qui parle).

## Hors portée

Le glossaire (les mots ne changent pas), la structure des écrans, le protocole. `docs/drive/principes-produit.md`, `recette.md` et le skill `repas-po` sont mis à jour par le PO dans la foulée.

## Critères d'acceptation

- Aucun « vous / votre / vos » ni impératif en « -ez » dans les textes de Repas (test).
- Captures lues à 360 px : pas de texte tronqué ni de ligne qui dépasse (le tutoiement est plus court, mais les accords changent).
- Agent : version incrémentée, empreinte recalculée ; une seule version par PR.

## Definition of Done

Celle du dépôt front ; liste ancien → nouveau pour les textes les plus vus (récapitulatif, choix de produit, erreurs) dans « À relire ».
