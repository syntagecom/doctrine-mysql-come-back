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
        if ($this->isSavepoint($sql)) {
            return false;
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
     */
    private function isSavepoint(?string $sql): bool
    {
        return str_starts_with(trim((string) $sql), 'SAVEPOINT');
    }
}
