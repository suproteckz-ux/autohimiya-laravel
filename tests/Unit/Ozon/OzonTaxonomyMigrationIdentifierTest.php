<?php

namespace Tests\Unit\Ozon;

use PHPUnit\Framework\TestCase;

class OzonTaxonomyMigrationIdentifierTest extends TestCase
{
    public function test_all_taxonomy_indexes_and_constraints_have_explicit_mysql_safe_names(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_08_07_000002_create_ozon_taxonomy_tables.php',
        );

        $expected = [
            'oz_tax_nodes_account_fk',
            'oz_tax_nodes_parent_fk',
            'oz_tax_nodes_account_cat_type_uq',
            'oz_tax_attr_node_fk',
            'oz_tax_attr_node_attr_uq',
        ];

        foreach ($expected as $identifier) {
            self::assertStringContainsString("'{$identifier}'", $migration);
            self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }

        self::assertSame(32, max(array_map('strlen', $expected)));
        self::assertDoesNotMatchRegularExpression('/->constrained\s*\(/', $migration);
        self::assertSame(3, substr_count($migration, '->foreign('));
        self::assertSame(2, substr_count($migration, '->unique('));
    }
}
