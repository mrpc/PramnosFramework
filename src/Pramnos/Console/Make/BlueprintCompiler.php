<?php

namespace Pramnos\Console\Make;

/**
 * Converts column definition arrays to SchemaBuilder Blueprint call strings
 * and assembles the PHP source for migration up()/down() method bodies.
 *
 * Stateless and dependency-free — all methods are pure string transformations.
 *
 * @package PramnosFramework
 */
class BlueprintCompiler
{
    /**
     * Derive a primary-key column name from a table name.
     *
     * Strips the #PREFIX# placeholder, removes a trailing 's', appends 'id'.
     * e.g. '#PREFIX#users' → 'userid', 'orders' → 'orderid'
     */
    public function getSingularPrimaryKey(string $tableName): string
    {
        $clean = str_replace('#PREFIX#', '', $tableName);
        if (substr($clean, -1) === 's') {
            $clean = substr($clean, 0, -1);
        }
        return strtolower($clean) . 'id';
    }

    /**
     * Convert a column definition array to a Blueprint fluent method-call string.
     *
     * The returned string ends with ';' and is ready to be placed inside a
     * SchemaBuilder closure body. Handles type-specific constructor arguments and
     * the full chain of fluent modifiers (nullable, default, unique, unsigned, comment).
     *
     * @param array{name:string, type:string, options:array, nullable:bool,
     *              default:mixed, unique:bool, unsigned:bool, comment:string} $col
     * @return string e.g. "$table->string('email', 255)->unique();"
     */
    public function blueprintCall(array $col): string
    {
        $name       = $col['name'];
        $type       = strtolower($col['type']);
        $opts       = $col['options'] ?? [];
        $isUnsigned = !empty($col['unsigned']) || !empty($opts['unsigned']);
        $len        = (int) ($opts['length'] ?? 255);

        $call = match ($type) {
            'string'       => "\$table->string('{$name}'" . ($len !== 255 ? ", {$len}" : '') . ")",
            'char'         => "\$table->char('{$name}', " . ($opts['length'] ?? 1) . ")",
            'integer'      => $isUnsigned
                                ? "\$table->unsignedInteger('{$name}')"
                                : "\$table->integer('{$name}')",
            'biginteger'   => $isUnsigned
                                ? "\$table->unsignedBigInteger('{$name}')"
                                : "\$table->bigInteger('{$name}')",
            'tinyinteger'  => "\$table->tinyInteger('{$name}')",
            'smallinteger' => "\$table->smallInteger('{$name}')",
            'decimal'      => "\$table->decimal('{$name}', "
                                . ($opts['total'] ?? 8) . ", " . ($opts['places'] ?? 2) . ")",
            'float'        => "\$table->float('{$name}', "
                                . ($opts['total'] ?? 8) . ", " . ($opts['places'] ?? 2) . ")",
            'double'       => "\$table->double('{$name}')",
            'boolean'      => "\$table->boolean('{$name}')",
            'text'         => "\$table->text('{$name}')",
            'mediumtext'   => "\$table->mediumText('{$name}')",
            'longtext'     => "\$table->longText('{$name}')",
            'date'         => "\$table->date('{$name}')",
            'time'         => "\$table->time('{$name}')",
            'datetime'     => "\$table->dateTime('{$name}')",
            'timestamp'    => "\$table->timestamp('{$name}')",
            'json'         => "\$table->json('{$name}')",
            'jsonb'        => "\$table->jsonb('{$name}')",
            'uuid'         => "\$table->uuid('{$name}')",
            'binary'       => "\$table->binary('{$name}')",
            default        => "\$table->string('{$name}')",
        };

        if (!empty($col['nullable'])) {
            $call .= '->nullable()';
        }
        if (isset($col['default']) && $col['default'] !== null) {
            $d = (string) $col['default'];
            if ($d === '') {
                $call .= "->default('')";
            } elseif (is_numeric($d)) {
                $call .= "->default({$d})";
            } elseif (in_array(strtolower($d), ['true', 'false', 'null'], true)) {
                $call .= '->default(' . strtolower($d) . ')';
            } else {
                $call .= "->default('" . addslashes($d) . "')";
            }
        }
        if (!empty($col['unique'])) {
            $call .= '->unique()';
        }
        // ->unsigned() is implicit for the *integer family; only chain it for other types
        if ($isUnsigned && !in_array($type, ['integer', 'biginteger', 'tinyinteger', 'smallinteger'], true)) {
            $call .= '->unsigned()';
        }
        if (!empty($col['comment'])) {
            $call .= "->comment('" . addslashes($col['comment']) . "')";
        }

        return $call . ';';
    }

    /**
     * Build the PHP source for a migration up() body using SchemaBuilder.
     *
     * Returns a string indented at 8 spaces (method-body level), ready to be
     * substituted for the {{ up_body }} stub token.
     *
     * @param string $tableName   Table name as it will appear in the DB (may include #PREFIX#)
     * @param bool   $hasPk       Whether to emit an auto-increment increments('…id') call
     * @param array  $columns     Column definitions (see blueprintCall() for shape)
     * @param bool   $timestamps  Whether to emit $table->timestamps()
     * @param bool   $softDeletes Whether to emit $table->softDeletes()
     * @param array  $foreignKeys [{column, references, on, onDelete}]
     * @return string PHP source, indented for insertion inside up()
     */
    public function buildMigrationUpBody(
        string $tableName,
        bool   $hasPk,
        array  $columns,
        bool   $timestamps,
        bool   $softDeletes,
        array  $foreignKeys
    ): string {
        $pad  = '        ';    // 8 spaces — method body
        $ipad = '            '; // 12 spaces — closure body

        $lines = [];

        if ($hasPk) {
            $pkName  = $this->getSingularPrimaryKey($tableName);
            $lines[] = $ipad . "\$table->increments('{$pkName}');";
        }

        foreach ($columns as $col) {
            $lines[] = $ipad . $this->blueprintCall($col);
        }

        if ($timestamps) {
            $lines[] = $ipad . "\$table->timestamps();";
        }
        if ($softDeletes) {
            $lines[] = $ipad . "\$table->softDeletes();";
        }

        if (!empty($foreignKeys)) {
            $lines[] = '';
            foreach ($foreignKeys as $fk) {
                $onDelete = !empty($fk['onDelete']) ? "->onDelete('{$fk['onDelete']}')" : '';
                $onUpdate = !empty($fk['onUpdate']) ? "->onUpdate('{$fk['onUpdate']}')" : '';
                $lines[] = $ipad
                    . "\$table->foreign('{$fk['column']}')"
                    . "->references('{$fk['references']}')"
                    . "->on('{$fk['on']}')"
                    . $onDelete
                    . $onUpdate . ';';
            }
        }

        $body = implode("\n", $lines);
        // Uses $schema instance variable — the caller (MakeCommandBase) prepends
        // "$schema = $this->application->database->schema();" once before all tables.
        return "{$pad}\$schema->createTable('{$tableName}', function (Blueprint \$table) {\n"
             . "{$body}\n"
             . "{$pad}});";
    }

    /**
     * Build the PHP source for a migration down() body.
     *
     * @return string PHP source, indented for insertion inside down()
     */
    public function buildMigrationDownBody(string $tableName): string
    {
        return "        \$this->application->database->schema()->dropIfExists('{$tableName}');";
    }
}
