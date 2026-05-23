<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: inspect a Pramnos ORM model class.
 *
 * Reflects on a given class to extract fillable fields, cast definitions,
 * table name, primary key, and declared relations so the AI can understand
 * the model's data shape without reading the source file manually.
 *
 * @package PramnosFramework
 */
class ModelInspectTool implements McpToolInterface
{
    public function name(): string
    {
        return 'model-inspect';
    }

    public function description(): string
    {
        return 'Inspect a Pramnos OrmModel class — fillable fields, casts, table name, primary key, relations.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'class' => [
                    'type'        => 'string',
                    'description' => 'Fully-qualified class name (e.g. App\\Models\\User).',
                ],
            ],
            'required' => ['class'],
        ];
    }

    public function execute(array $input): mixed
    {
        $class = trim($input['class'] ?? '');
        if ($class === '') {
            return ['error' => 'class parameter is required'];
        }

        if (!class_exists($class)) {
            return ['error' => "Class not found: {$class}"];
        }

        try {
            $ref = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            return ['error' => $e->getMessage()];
        }

        $result = [
            'class'       => $class,
            'parent'      => $ref->getParentClass() ? $ref->getParentClass()->getName() : null,
            'table'       => $this->readProperty($ref, 'table'),
            'primaryKey'  => $this->readProperty($ref, 'primaryKey') ?? $this->readProperty($ref, 'pk'),
            'fillable'    => $this->readProperty($ref, 'fillable')    ?? [],
            'casts'       => $this->readProperty($ref, 'casts')       ?? [],
            'relations'   => $this->findRelations($ref),
            'methods'     => $this->findPublicMethods($ref),
        ];

        return $result;
    }

    private function readProperty(\ReflectionClass $ref, string $name): mixed
    {
        if (!$ref->hasProperty($name)) {
            return null;
        }
        $prop = $ref->getProperty($name);
        if ($prop->isStatic()) {
            return $prop->getValue();
        }
        // For instance properties, try to get the default value
        $defaults = $ref->getDefaultProperties();
        return $defaults[$name] ?? null;
    }

    /** Find methods that look like relation definitions (return OrmModel instances / arrays). */
    private function findRelations(\ReflectionClass $ref): array
    {
        $relations = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $ref->getName()) {
                continue;
            }
            $body = $this->readMethodBody($method);
            if ($body !== null && preg_match('/hasOne|hasMany|belongsTo|belongsToMany|hasManyThrough/i', $body)) {
                $relations[] = $method->getName();
            }
        }
        return $relations;
    }

    private function findPublicMethods(\ReflectionClass $ref): array
    {
        $methods = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === $ref->getName()
                && !$method->isConstructor()
                && !$method->isDestructor()) {
                $methods[] = $method->getName();
            }
        }
        return $methods;
    }

    private function readMethodBody(\ReflectionMethod $method): ?string
    {
        $file  = $method->getFileName();
        $start = $method->getStartLine();
        $end   = $method->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return null;
        }
        $lines = file($file);
        if ($lines === false) {
            return null;
        }
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
