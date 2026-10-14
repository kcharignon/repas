# 0006 — Deux buses Messenger : `command.bus` et `query.bus`

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0003](0003-cqrs-lecture-ecriture.md), [0012](0012-pieges-compliance.md), `presentation-archi/presentation.md` §11

## Contexte

`config/packages/messenger.yaml` est celui par défaut : aucun bus déclaré, donc un seul `messenger.bus.default` synchrone. `CommandBus` s'appuie sur `MessageBusInterface` (autowiring) et `QueryBus` est câblé explicitement sur `@messenger.bus.default` (`config/services.yaml`). `CommandBus::dispatch()` déballe `HandlerFailedException` pour relancer la `DomainException` d'origine ; `QueryBus::ask()` ne le fait pas, donc une exception de domaine levée dans une query ressortirait enveloppée. `QueryBusInterface::ask(object)` accepte n'importe quel objet.

Compliance déclare `query.bus` mais route aussi les queries sur `command.bus` (donc sous son middleware de transaction Doctrine) : piège à ne pas reproduire.

## Décision

1. Deux buses distincts dans `messenger.yaml` : `command.bus` (bus par défaut) et `query.bus`. Aucun middleware de transaction sur `query.bus`.
2. `CommandBus` est câblé sur `@command.bus`, `QueryBus` sur `@query.bus`.
3. Marqueur `Repas\Shared\Application\Interface\QueryInterface` (interface vide). `QueryBusInterface::ask(QueryInterface $query): mixed`. Toute query l'implémente ; le typage empêche de passer une commande au query bus.
4. Les `QueryHandler` déclarent `#[AsMessageHandler(bus: 'query.bus')]` pour ne s'exécuter que sur `query.bus`. Les `CommandHandler` existants gardent `#[AsMessageHandler]` simple (inchangés).
5. `QueryBus::ask()` déballe `HandlerFailedException` comme `CommandBus::dispatch()` : la `DomainException` d'origine remonte au controller.
6. Les controllers injectent toujours l'interface (`CommandBusInterface` / `QueryBusInterface`), jamais la classe concrète.
7. Les commandes restent sans marqueur pour l'instant (`dispatch(object)`) ; introduire `CommandInterface` plus tard serait un ADR séparé.

8. **`command.bus` est transactionnel** (phase 9) : middleware `doctrine_transaction`. Une commande est atomique : si son handler, ou un listener qu'il déclenche (les événements s'exécutent dans la même transaction, les commandes imbriquées en savepoint ; DBAL 4 active toujours les savepoints, l'option `use_savepoints` de `doctrine.yaml` n'existe plus), lève une exception, tout ce qui a été écrit est annulé. Constaté en phase 9 : sans ce middleware, `POST /ingredients` répondait 422 en laissant l'ingrédient créé. `CommandBusTransactionTest` le prouve avec un handler factice qui écrit puis échoue.

## Conséquences

- Positives : les queries ne passent plus par les middlewares d'écriture ; isolation claire ; les exceptions de domaine se comportent pareil en lecture et en écriture.
- Coûts : `bin/console debug:messenger` à connaître pour diagnostiquer ; une query mal déclarée (sans `bus:`) serait aussi enregistrée sur `command.bus`.
- Garde-fous : tests unitaires de `QueryBus` et `CommandBus` (`tests/Helper/MockMessageBus.php`) ; `BusWiringTest` (kernel, vrais buses, handlers factices `tests/Helper/Bus/` déclarés sur un seul bus : prouve que chaque wrapper est câblé sur le bon bus, que le bus d'en face ne trouve pas le handler, et que la `DomainException` ressort déballée d'un vrai handler) ; `QueryConventionTest` (chaque `QueryHandler` de `src/` porte `bus: 'query.bus'`, chaque `Query` a son handler). Ce dernier remplace la « convention + revue » : un oubli du `bus:` ne casse rien à l'exécution, seul ce test le voit.
