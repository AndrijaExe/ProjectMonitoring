<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter;

use App\Adapter\Persistence\Postgres\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class PostgresConnectionTest extends TestCase
{
    public function testADirectConnectionUsesServerSidePreparedStatements(): void
    {
        $connection = new PostgresConnection('postgresql://user:pass@db.example.com:5432/monitoring?sslmode=require');

        self::assertFalse($connection->emulatesPreparedStatements());
    }

    public function testTheSupabaseTransactionPoolerSwitchesToEmulation(): void
    {
        $connection = new PostgresConnection(
            'postgresql://user:pass@aws-0-eu-central-1.pooler.supabase.com:6543/postgres?sslmode=require',
        );

        self::assertTrue($connection->emulatesPreparedStatements());
    }

    public function testTheSessionPoolerOnThePlainPortKeepsRealPreparedStatements(): void
    {
        $connection = new PostgresConnection(
            'postgresql://user:pass@aws-0-eu-central-1.pooler.supabase.com:5432/postgres?sslmode=require',
        );

        self::assertFalse($connection->emulatesPreparedStatements());
    }

    public function testAnExplicitPgbouncerFlagIsHonoured(): void
    {
        $connection = new PostgresConnection('postgresql://user:pass@pooler.example.com:5432/app?pgbouncer=true');

        self::assertTrue($connection->emulatesPreparedStatements());
    }

    public function testAMissingUrlSaysSoInsteadOfFailingLater(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DATABASE_URL is not configured.');

        (new PostgresConnection(''))->emulatesPreparedStatements();
    }
}
