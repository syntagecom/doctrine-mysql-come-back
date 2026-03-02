<?php

namespace Facile\DoctrineMySQLComeBack\Tests\Unit\Detector;

use PHPUnit\Framework\Attributes\DataProvider;
use Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Detector\PostgreSQLGoneAwayDetector;
use Facile\DoctrineMySQLComeBack\Tests\Unit\BaseUnitTestCase;

class PostgreSQLGoneAwayDetectorTest extends BaseUnitTestCase
{
    private const SSL_CLOSED = 'SSL connection has been closed unexpectedly';

    private const NO_CONNECTION = 'no connection to the server';

    private const RECOVERY_CONFLICT = 'SQLSTATE[40001]: Serialization failure: 7 ERROR: canceling statement due to conflict with recovery';

    private const NOT_RETRYABLE_ERROR = 'Unknown error';

    #[DataProvider('isUpdateQueryDataProvider')]
    public function testIsUpdateQuery(string $query, bool $isUpdate): void
    {
        // Use RECOVERY_CONFLICT because it's only in $goneAwayExceptions (not $goneAwayInUpdateExceptions),
        // so it correctly demonstrates that UPDATE queries are NOT retried for this error type.
        $error = new \Exception(self::RECOVERY_CONFLICT);

        $goneAwayDetector = new PostgreSQLGoneAwayDetector();

        $this->assertSame(! $isUpdate, $goneAwayDetector->isGoneAwayException($error, $query));
        $this->assertTrue($goneAwayDetector->isGoneAwayException($error, 'SELECT 1'));
    }

    #[DataProvider('savepointDataProvider')]
    public function testSavepointShouldNotBeRetried(string $sql): void
    {
        $error = new \Exception(self::SSL_CLOSED);

        $goneAwayDetector = new PostgreSQLGoneAwayDetector();

        $this->assertFalse($goneAwayDetector->isGoneAwayException($error, $sql));
        $this->assertTrue($goneAwayDetector->isGoneAwayException($error, 'SELECT 1'));
    }

    #[DataProvider('isGoneAwayExceptionDataProvider')]
    public function testIsGoneAwayException(string $message, bool $isUpdate, bool $expectedIsGoneAwayException): void
    {
        $error = new \Exception($message);
        $query = $isUpdate ? 'DELETE FROM table1;' : 'SELECT 1;';

        $this->assertSame(
            $expectedIsGoneAwayException,
            (new PostgreSQLGoneAwayDetector())->isGoneAwayException($error, $query)
        );
    }

    /**
     * @return array{string, bool}[]
     */
    public static function isUpdateQueryDataProvider(): array
    {
        return [
            ['UPDATE ', true],
            ['DELETE ', true],
            ['INSERT ', true],
            ['SELECT ', false],
            ['select ', false],
            ["\n\tSELECT\n", false],
            ['(select ', false],
            [' (select ', false],
            [' 
            (select ', false],
            [' UPDATE WHERE (SELECT ', true],
            [' UPDATE WHERE 
            (select ', true],
        ];
    }

    /**
     * @return array{string}[]
     */
    public static function savepointDataProvider(): array
    {
        return [
            ['SAVEPOINT foo'],
            ['   SAVEPOINT foo'],
            ['
            SAVEPOINT foo'],
        ];
    }

    /**
     * @return array{0: string, 1: bool, 2: bool}[]
     */
    public static function isGoneAwayExceptionDataProvider(): array
    {
        return [
            // SSL closed - retryable for both read and update
            [self::SSL_CLOSED, true, true],
            [self::SSL_CLOSED, false, true],
            // No connection - retryable for both read and update
            [self::NO_CONNECTION, true, true],
            [self::NO_CONNECTION, false, true],
            // Recovery conflict - retryable for read only, NOT for update
            [self::RECOVERY_CONFLICT, false, true],
            [self::RECOVERY_CONFLICT, true, false],
            // Unknown error - never retryable
            [self::NOT_RETRYABLE_ERROR, true, false],
            [self::NOT_RETRYABLE_ERROR, false, false],
        ];
    }
}
