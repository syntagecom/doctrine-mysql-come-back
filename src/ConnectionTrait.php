<?php

declare(strict_types=1);

namespace Facile\DoctrineMySQLComeBack\Doctrine\DBAL;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement as DBALStatement;
use Doctrine\DBAL\Types\Type;
use Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Detector\GoneAwayDetector;
use Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Detector\MySQLGoneAwayDetector;
use Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Detector\PostgreSQLGoneAwayDetector;
use STS\Backoff\Backoff;
use STS\Backoff\Strategies\ExponentialStrategy;

/**
 * @psalm-require-extends Connection
 *
 * @psalm-type WrapperParameterType = string|Type|ParameterType|ArrayParameterType
 * @psalm-type WrapperParameterTypeArray = array<int<0, max>, WrapperParameterType>|array<string, WrapperParameterType>
 */
trait ConnectionTrait
{
    protected GoneAwayDetector $goneAwayDetector;

    protected int $maxReconnectAttempts = 0;

    private int $reconnectDelay = 0;

    private bool $hasBeenClosedWithAnOpenTransaction = false;

    private bool $currentlyOpeningFirstLevelTransaction = false;

    private ?\ReflectionProperty $selfReflectionNestingLevelProperty = null;

    public function __construct(
        array $params,
        Driver $driver,
        ?Configuration $config = null
    ) {
        $this->commonConstructor($params, $driver, $config);
    }

    private function commonConstructor(array &$params, Driver $driver, ?Configuration $config): void
    {
        if (isset($params['driverOptions']['x_reconnect_attempts'])) {
            $this->maxReconnectAttempts = $this->validateAttemptsOption($params['driverOptions']['x_reconnect_attempts']);
            unset($params['driverOptions']['x_reconnect_attempts']);
        }

        if (isset($params['driverOptions']['x_reconnect_delay'])) {
            $this->reconnectDelay = $this->validateReconnectDelayOption($params['driverOptions']['x_reconnect_delay']);
            unset($params['driverOptions']['x_reconnect_delay']);
        }
        $this->goneAwayDetector = match (true) {
            is_a($params['driverClass'], Driver\PDO\PgSQL\Driver::class, true) => new PostgreSQLGoneAwayDetector(),
            is_a($params['driverClass'], Driver\PDO\MySQL\Driver::class, true) => new MySQLGoneAwayDetector(),
            default => throw new \LogicException('Unsupported driver ' . $driver::class)
        };

        /**
         * @psalm-suppress InternalMethod
         * @psalm-suppress MixedArgumentTypeCoercion
         */
        parent::__construct($params, $driver, $config);
    }

    private function validateAttemptsOption(mixed $attempts): int
    {
        if (! is_int($attempts)) {
            throw new \InvalidArgumentException('Invalid x_reconnect_attempts option: expecting int, got ' . gettype($attempts));
        }

        if ($attempts < 0) {
            throw new \InvalidArgumentException('Invalid x_reconnect_attempts option: it must not be negative');
        }

        return $attempts;
    }

    private function validateReconnectDelayOption(mixed $delay): int
    {
        if (! is_int($delay)) {
            throw new \InvalidArgumentException('Invalid x_reconnect_delay option: expecting int, got ' . gettype($delay));
        }

        if ($delay < 0) {
            throw new \InvalidArgumentException('Invalid x_reconnect_delay option: it must not be negative');
        }

        return $delay;
    }

    public function setGoneAwayDetector(GoneAwayDetector $goneAwayDetector): void
    {
        $this->goneAwayDetector = $goneAwayDetector;
    }

    /**
     * @template R
     *
     * @param callable():R $callable
     *
     * @return R
     */
    public function doWithRetry(callable $callable, ?string $sql = null)
    {
        $backoff = (new Backoff())
            ->setMaxAttempts($this->maxReconnectAttempts)
            ->setStrategy(new ExponentialStrategy($this->reconnectDelay))
            ->enableJitter()
            ->setDecider(function (int $attempt, int $maxAttempts, mixed $result, ?\Throwable $exception = null) use ($sql): bool {
                if ($exception !== null && ! $this->canTryAgain(throwable: $exception, sql: $sql)) {
                    throw $exception;
                }

                if ($exception !== null && $attempt >= $maxAttempts) {
                    throw $exception;
                }

                if ($exception !== null) {
                    $this->close();
                }

                return $attempt < $maxAttempts && $exception !== null;
            });

        return $backoff->run($callable);
    }

    public function connect(?string $connectionName = null): DriverConnection
    {
        $this->hasBeenClosedWithAnOpenTransaction = false;

        /** @psalm-suppress InternalMethod */
        return parent::connect($connectionName);
    }

    public function close(): void
    {
        if ($this->getTransactionNestingLevel() > 0) {
            $this->hasBeenClosedWithAnOpenTransaction = true;
        }

        parent::close();
    }

    public function prepare(string $sql): DBALStatement
    {
        return $this->doWithRetry(function () use ($sql): Statement {
            $dbalStatement = parent::prepare($sql);

            return Statement::fromDBALStatement($this, $dbalStatement);
        });
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     *
     * @psalm-param WrapperParameterTypeArray $types
     */
    public function executeQuery(string $sql, array $params = [], $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return $this->doWithRetry(fn(): Result => parent::executeQuery($sql, $params, $types, $qcp), $sql);
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     *
     * @psalm-param WrapperParameterTypeArray $types
     *
     * @return int|numeric-string
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return $this->doWithRetry(fn() => parent::executeStatement($sql, $params, $types), $sql);
    }

    public function beginTransaction(): void
    {
        if ($this->getTransactionNestingLevel() === 0) {
            $this->currentlyOpeningFirstLevelTransaction = true;
        }

        $this->doWithRetry(function (): void {
            parent::beginTransaction();
        });

        $this->currentlyOpeningFirstLevelTransaction = false;
    }

    public function canTryAgain(\Throwable $throwable, ?string $sql = null): bool
    {
        /**
         * We only retry at statement level for genuine connection/transient errors **outside**
         * of an active transaction. Two distinct guards are required here, covering different
         * failure modes:
         *
         * - Guard #1 (nesting > 0): runtime view — DBAL currently believes a tx is active.
         *   In this case we must NOT retry a single statement because:
         *     (a) If the server-side tx is still alive, a single-statement retry can break
         *         atomicity/isolation (duplicate effects, reorder writes) and interfere with
         *         locks/savepoints.
         *     (b) If the server-side tx has already been lost (network reset/server restart)
         *         while DBAL still reports nesting > 0, an implicit reconnect would run the
         *         retried statement outside the original BEGIN (autocommit), causing partial
         *         writes and/or 25P01/25P02.
         *   The only exception is the short, controlled window where we are opening the
         *   first-level transaction (currentlyOpeningFirstLevelTransaction === true): BEGIN
         *   itself may be retried safely to establish a fresh transactional boundary.
         */
        if ($this->getTransactionNestingLevel() > 0 && ! $this->currentlyOpeningFirstLevelTransaction) {
            return false;
        }

        /**
         * - Guard #2 (hasBeenClosedWithAnOpenTransaction): historical view — we **know** this
         *   connection was explicitly closed while a tx was open (the flag is set in close()
         *   when nesting > 0). This implies the server-side tx was rolled back and all session
         *   state (savepoints, locks, SET LOCAL, temp tables) was lost.
         *
         *   Even if nesting is now 0 after a reconnect, immediately retrying a single statement
         *   would run it in autocommit before a fresh BEGIN is established, risking partial
         *   writes and inconsistent side effects. Therefore we also block statement-level retry
         *   in this historical condition **until** we are explicitly opening the first-level
         *   transaction again (currentlyOpeningFirstLevelTransaction === true).
         *
         *   In short:
         *   - Guard #1 protects while DBAL *currently* thinks a tx is active.
         *   - Guard #2 protects *after* an improper close occurred in the middle of a tx,
         *     until a clean BEGIN is being (re)opened.
         *   Both are necessary and complementary.
         */
        if ($this->hasBeenClosedWithAnOpenTransaction && ! $this->currentlyOpeningFirstLevelTransaction) {
            return false;
        }

        return $this->goneAwayDetector->isGoneAwayException($throwable, $sql);
    }
}
