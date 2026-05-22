<?php

namespace Pramnos\Console\Make;

/**
 * Generates plausible PHP expressions for seeder column values.
 *
 * Uses column-name heuristics first, then type-based fallbacks.
 * Every returned expression uses $i as a 1-based loop counter variable
 * so it can be dropped directly into a for-loop seeder body.
 *
 * Stateless and dependency-free — all methods are pure string transformations.
 *
 * @package PramnosFramework
 */
class FakeDataGenerator
{
    /**
     * Column-name substrings mapped to PHP fake-value expressions.
     * Checked via str_contains() — first match wins.
     *
     * @var array<string,string>
     */
    private const HINTS = [
        'email'       => "'user' . \$i . '@example.com'",
        'first_name'  => "['Alice','Bob','Charlie','Diana','Eva'][\$i % 5]",
        'last_name'   => "['Smith','Jones','Taylor','Brown','Wilson'][\$i % 5]",
        'username'    => "'user_' . \$i",
        'login'       => "'user_' . \$i",
        'name'        => "'Name ' . \$i",
        'title'       => "'Title ' . \$i",
        'phone'       => "'+30210' . str_pad(\$i, 7, '0', STR_PAD_LEFT)",
        'mobile'      => "'+306900' . str_pad(\$i, 6, '0', STR_PAD_LEFT)",
        'address'     => "\$i . ' Main Street'",
        'city'        => "['Athens','Thessaloniki','Patras','Heraklion','Larissa'][\$i % 5]",
        'country'     => "'Greece'",
        'description' => "'Sample description ' . \$i",
        'body'        => "'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Record ' . \$i",
        'content'     => "'Content for record ' . \$i",
        'slug'        => "'record-' . \$i",
        'url'         => "'https://example.com/item-' . \$i",
        'ip'          => "'192.168.' . rand(0, 255) . '.' . rand(1, 254)",
        'password'    => "password_hash('password' . \$i, PASSWORD_DEFAULT)",
        'token'       => "bin2hex(random_bytes(16))",
        'status'      => "['active','inactive','pending'][\$i % 3]",
        'type'        => "['type_a','type_b','type_c'][\$i % 3]",
        'color'       => "['#FF5733','#33FF57','#3357FF','#F3FF33','#FF33F3'][\$i % 5]",
        'latitude'    => "37.97 + (\$i * 0.001)",
        'longitude'   => "23.73 + (\$i * 0.001)",
        'lat'         => "37.97 + (\$i * 0.001)",
        'lng'         => "23.73 + (\$i * 0.001)",
        'lon'         => "23.73 + (\$i * 0.001)",
        'price'       => "round(\$i * 9.99, 2)",
        'amount'      => "round(\$i * 100.0, 2)",
        'score'       => "\$i * 10",
        'sort'        => "\$i",
        'order'       => "\$i",
        'position'    => "\$i",
        'weight'      => "\$i * 0.5",
    ];

    /**
     * Generate a PHP expression that produces a plausible fake value for a column.
     *
     * @param string $colName Column name (used for name-based heuristics)
     * @param string $colType Blueprint type string (string, integer, boolean, …)
     * @param array  $options Blueprint constructor options (length, total, places, …)
     * @return string PHP expression without trailing semicolon
     */
    public function generateFakeValue(string $colName, string $colType, array $options = []): string
    {
        $lower = strtolower($colName);

        foreach (self::HINTS as $hint => $code) {
            if (str_contains($lower, $hint)) {
                return $code;
            }
        }

        return match (strtolower($colType)) {
            'integer', 'tinyinteger', 'smallinteger', 'biginteger'
                                        => "\$i",
            'decimal', 'float', 'double'
                                        => "round(\$i * 9.99, 2)",
            'boolean'                   => "(\$i % 2 === 0)",
            'date'                      => "date('Y-m-d', strtotime('-' . \$i . ' days'))",
            'datetime', 'timestamp'     => "date('Y-m-d H:i:s', strtotime('-' . \$i . ' hours'))",
            'text', 'mediumtext', 'longtext'
                                        => "'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Row ' . \$i",
            'json', 'jsonb'             => "json_encode(['item' => \$i, 'active' => true])",
            'uuid'                      => "sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',"
                                         . " mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),"
                                         . " mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,"
                                         . " mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff))",
            default                     => "'value_' . \$i",
        };
    }

    /**
     * Build the fields block for a seeder template (the {{ fields }} stub token).
     *
     * Skips auto-managed columns: id, created_at, updated_at, deleted_at.
     * Lines are indented to 16 spaces so they land correctly inside the
     * $this->insert(…) call in the seeder template.
     *
     * @param array $columns Column definitions (same shape as BlueprintCompiler::blueprintCall())
     * @return string Multi-line PHP key => value pairs, without surrounding braces
     */
    public function buildSeederFields(array $columns): string
    {
        $skip  = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $ipad  = '                '; // 16 spaces
        $lines = [];

        foreach ($columns as $col) {
            if (in_array($col['name'], $skip, true)) {
                continue;
            }
            $fake    = $this->generateFakeValue($col['name'], $col['type'], $col['options'] ?? []);
            $lines[] = $ipad . "'{$col['name']}' => {$fake},";
        }

        return implode("\n", $lines);
    }
}
