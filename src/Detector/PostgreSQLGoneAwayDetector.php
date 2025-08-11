<?php

namespace Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Detector;

class PostgreSQLGoneAwayDetector implements GoneAwayDetector
{
    /** @var string[] */
    protected array $goneAwayExceptions = [
        'SSL connection has been closed unexpectedly',
        'no connection to the server',
    ];

    /** @var string[] */
    protected array $goneAwayInUpdateExceptions = [
        'SSL connection has been closed unexpectedly',
        'no connection to the server',
    ];

    public function isGoneAwayException(\Throwable $exception, ?string $sql = null): bool
    {
        // Do NOT retry savepoints
        if ($this->isSavepoint($sql)) {
            return false;
        }

        // If exception exposes SQLSTATE, do not retry invalid tx state
        // @see https://www.postgresql.org/docs/current/errcodes-appendix.html
        if (method_exists($exception, 'getSQLState')) {
            $state = $exception->getSQLState();

            // 25P01 = "no active SQL transaction" (e.g., SAVEPOINT outside BEGIN)
            // 25P02 = "in failed SQL transaction" (tx aborted; commands ignored until ROLLBACK)
            // Reconnecting/retrying won't fix state errors
            if ($state === '25P01' || $state === '25P02') {
                return false;
            }

            // Retry genuine connection failures (class 08)
            if (is_string($state) && str_starts_with($state, '08')) {
                return true;
            }

            // Retry server shutdown / cannot connect now – transient conditions
            if (in_array($state, ['57P01', '57P02', '57P03'], true)) {
                return true;
            }

            // (Optional) Retry too many connections (environment-dependent)
            // if ($state === '53300') {
            //     return true;
            // }
        }

        if ($this->isUpdateQuery($sql)) {
            $possibleMatches = $this->goneAwayInUpdateExceptions;
        } else {
            $possibleMatches = $this->goneAwayExceptions;
        }

        $message = $exception->getMessage();

        foreach ($possibleMatches as $goneAwayException) {
            if (str_contains($message, $goneAwayException)) {
                return true;
            }
        }

        return false;
    }

    private function isUpdateQuery(?string $sql): bool
    {
        return ! preg_match('/^[\s\n\r\t(]*(select|show|describe)[\s\n\r\t(]+/i', (string) $sql);
    }

    /**
     * @see \Doctrine\DBAL\Platforms\AbstractPlatform::createSavePoint
     * * Also cover RELEASE and ROLLBACK TO SAVEPOINT.
     */
    private function isSavepoint(?string $sql): bool
    {
        if ($sql === null) {
            return false;
        }
        $s = ltrim($sql);
        return (bool) preg_match(
            '/^(SAVEPOINT|RELEASE\s+SAVEPOINT|ROLLBACK\s+TO\s+SAVEPOINT)\b/i',
            $s
        );
    }
}
