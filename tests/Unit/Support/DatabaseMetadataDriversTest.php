<?php

namespace ESolution\DataSources\Tests\Unit\Support;

use ESolution\DataSources\Database\Drivers\MySqlDatabaseDriver;
use ESolution\DataSources\Database\Drivers\PostgresDatabaseDriver;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;

class DatabaseMetadataDriversTest extends TestCase
{
    public function test_mysql_driver_normalizes_table_column_index_and_foreign_key_metadata(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(4))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                [
                    (object) ['table_name' => 'users'],
                    (object) ['table_name' => 'orders'],
                ],
                [
                    (object) [
                        'name' => 'id',
                        'data_type' => 'bigint unsigned',
                        'is_nullable' => 'NO',
                        'column_default' => null,
                        'column_key' => 'PRI',
                        'extra' => 'auto_increment',
                    ],
                    (object) [
                        'name' => 'user_id',
                        'data_type' => 'bigint unsigned',
                        'is_nullable' => 'YES',
                        'column_default' => null,
                        'column_key' => 'MUL',
                        'extra' => '',
                    ],
                ],
                [
                    (object) ['index_name' => 'PRIMARY', 'column_name' => 'id', 'non_unique' => 0, 'seq_in_index' => 1],
                    (object) ['index_name' => 'orders_user_id_index', 'column_name' => 'user_id', 'non_unique' => 1, 'seq_in_index' => 1],
                ],
                [
                    (object) [
                        'constraint_name' => 'orders_user_id_foreign',
                        'column_name' => 'user_id',
                        'referenced_table_name' => 'users',
                        'referenced_column_name' => 'id',
                    ],
                ]
            );

        $driver = new MySqlDatabaseDriver();

        $this->assertSame(['users', 'orders'], $driver->listTables($connection));
        $this->assertSame([
            [
                'name' => 'id',
                'type' => 'bigint unsigned',
                'nullable' => false,
                'default' => null,
                'key' => 'PRI',
                'primary' => true,
                'foreign' => false,
                'extra' => 'auto_increment',
            ],
            [
                'name' => 'user_id',
                'type' => 'bigint unsigned',
                'nullable' => true,
                'default' => null,
                'key' => 'MUL',
                'primary' => false,
                'foreign' => true,
                'extra' => '',
            ],
        ], $driver->listColumns($connection, 'orders'));
        $this->assertSame([
            [
                'name' => 'PRIMARY',
                'column' => 'id',
                'unique' => true,
                'primary' => true,
                'sequence' => 1,
            ],
            [
                'name' => 'orders_user_id_index',
                'column' => 'user_id',
                'unique' => false,
                'primary' => false,
                'sequence' => 1,
            ],
        ], $driver->listIndexes($connection, 'orders'));
        $this->assertSame([
            [
                'name' => 'orders_user_id_foreign',
                'column' => 'user_id',
                'referenced_table' => 'users',
                'referenced_column' => 'id',
            ],
        ], $driver->listForeignKeys($connection, 'orders'));
    }

    public function test_postgres_driver_normalizes_table_column_index_and_foreign_key_metadata(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(7))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                [
                    (object) ['table_schema' => 'public', 'table_name' => 'users'],
                    (object) ['table_schema' => 'audit', 'table_name' => 'entries'],
                ],
                [
                    (object) [
                        'name' => 'id',
                        'data_type' => 'bigint',
                        'is_nullable' => 'NO',
                        'column_default' => "nextval('orders_id_seq'::regclass)",
                        'udt_name' => 'int8',
                    ],
                    (object) [
                        'name' => 'user_id',
                        'data_type' => 'bigint',
                        'is_nullable' => 'YES',
                        'column_default' => null,
                        'udt_name' => 'int8',
                    ],
                ],
                [
                    (object) ['column_name' => 'id'],
                ],
                [
                    (object) [
                        'constraint_name' => 'orders_user_id_foreign',
                        'column_name' => 'user_id',
                        'referenced_table_schema' => 'public',
                        'referenced_table_name' => 'users',
                        'referenced_column_name' => 'id',
                    ],
                ],
                [
                    (object) [
                        'index_name' => 'orders_pkey',
                        'column_name' => 'id',
                        'is_unique' => true,
                        'is_primary' => true,
                        'position' => 1,
                    ],
                    (object) [
                        'index_name' => 'orders_user_id_idx',
                        'column_name' => 'user_id',
                        'is_unique' => false,
                        'is_primary' => false,
                        'position' => 1,
                    ],
                ],
                [
                    (object) [
                        'index_name' => 'orders_pkey',
                        'column_name' => 'id',
                        'is_unique' => true,
                        'is_primary' => true,
                        'position' => 1,
                    ],
                    (object) [
                        'index_name' => 'orders_user_id_idx',
                        'column_name' => 'user_id',
                        'is_unique' => false,
                        'is_primary' => false,
                        'position' => 1,
                    ],
                ],
                [
                    (object) [
                        'constraint_name' => 'orders_user_id_foreign',
                        'column_name' => 'user_id',
                        'referenced_table_schema' => 'public',
                        'referenced_table_name' => 'users',
                        'referenced_column_name' => 'id',
                    ],
                ]
            );

        $driver = new PostgresDatabaseDriver();

        $this->assertSame(['users', 'audit.entries'], $driver->listTables($connection));
        $this->assertSame([
            [
                'name' => 'id',
                'type' => 'bigint',
                'nullable' => false,
                'default' => "nextval('orders_id_seq'::regclass)",
                'key' => 'PRI',
                'primary' => true,
                'foreign' => false,
                'extra' => '',
            ],
            [
                'name' => 'user_id',
                'type' => 'bigint',
                'nullable' => true,
                'default' => null,
                'key' => 'MUL',
                'primary' => false,
                'foreign' => true,
                'extra' => '',
            ],
        ], $driver->listColumns($connection, 'public.orders'));
        $this->assertSame([
            [
                'name' => 'orders_pkey',
                'column' => 'id',
                'unique' => true,
                'primary' => true,
                'sequence' => 1,
            ],
            [
                'name' => 'orders_user_id_idx',
                'column' => 'user_id',
                'unique' => false,
                'primary' => false,
                'sequence' => 1,
            ],
        ], $driver->listIndexes($connection, 'public.orders'));
        $this->assertSame([
            [
                'name' => 'orders_user_id_foreign',
                'column' => 'user_id',
                'referenced_table' => 'users',
                'referenced_column' => 'id',
            ],
        ], $driver->listForeignKeys($connection, 'public.orders'));
    }
}
