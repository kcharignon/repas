<?php

namespace Repas\Tests\Helper\SqlBudget;


use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Middleware DBAL qui alimente `QueryCounter`. Enregistré par `config/services.yaml` (`when@test`) : jamais en dev
 * ni en prod.
 */
final readonly class CountingMiddleware implements Middleware
{
    public function __construct(
        private QueryCounter $counter,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        $counter = $this->counter;

        return new class($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $counter = $this->counter;

                return new class(parent::connect($params), $counter) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly QueryCounter $counter)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $counter = $this->counter;

                        return new class(parent::prepare($sql), $sql, $counter) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly string $sql, private readonly QueryCounter $counter)
                            {
                                parent::__construct($statement);
                            }

                            public function execute($params = null): Result
                            {
                                $this->counter->record($this->sql);

                                return parent::execute($params);
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->counter->record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int
                    {
                        $this->counter->record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
