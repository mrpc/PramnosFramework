<?php

namespace Pramnos\Console\Make;

/**
 * Resolves PHP class names and namespaces from entity names and application config.
 *
 * All public methods are static and operate on plain strings or the
 * $application->applicationInfo config array — no running application or
 * database connection is required, making this class fully unit-testable.
 *
 */
class NamespaceResolver
{
    /**
     * Convert a raw entity name to a proper PascalCase PHP class name.
     *
     * When $forceSingular = true  (models):   'users' → 'User',  'user' → 'User'
     * When $forceSingular = false (views):    'user'  → 'Users', 'users' → 'Users'
     *
     * @param string $name          Raw entity name from a CLI argument
     * @param bool   $forceSingular True for models; false for views/controllers
     */
    public static function getProperClassName(string $name, bool $forceSingular = true): string
    {
        if ($forceSingular) {
            if (\Pramnos\General\StringHelper::isPlural($name)) {
                return ucfirst(\Pramnos\General\StringHelper::singularize($name));
            }
            return ucfirst($name);
        } else {
            if (\Pramnos\General\StringHelper::isPlural($name)) {
                return ucfirst($name);
            }
            return ucfirst(\Pramnos\General\StringHelper::pluralize($name));
        }
    }

    /**
     * Derive the conventional table name (with the #PREFIX# placeholder) from an entity name.
     *
     * Always returns a plural lowercase name with the placeholder prefix.
     * e.g. 'User' → '#PREFIX#users', 'users' → '#PREFIX#users'
     */
    public static function getModelTableName(string $name): string
    {
        $name = strtolower($name);
        if (\Pramnos\General\StringHelper::isPlural($name)) {
            return '#PREFIX#' . $name;
        }
        return '#PREFIX#' . \Pramnos\General\StringHelper::pluralize($name);
    }

    /**
     * Derive the base PHP namespace from an application info array and app name.
     *
     * Returns e.g. 'MyApp\MyAppName' (no trailing backslash, no sub-namespace).
     * Callers append '\\Models', '\\Controllers', etc. as needed.
     *
     * @param array  $applicationInfo The $application->applicationInfo config array
     * @param string $appName         The $application->appName value (may be empty)
     */
    public static function resolveBaseNamespace(array $applicationInfo, string $appName): string
    {
        $namespace = $applicationInfo['namespace'] ?? 'App';
        if ($appName !== '') {
            $namespace .= '\\' . $appName;
        }
        return $namespace;
    }

    /**
     * Build the INCLUDES-relative filesystem base path for an application.
     *
     * Returns e.g. '/var/www/html/INCLUDES/AppName' (no trailing separator).
     * Callers append DIRECTORY_SEPARATOR . 'Models', etc. as needed.
     *
     * @param string $root     Value of the ROOT constant
     * @param string $includes Value of the INCLUDES constant
     * @param string $appName  The $application->appName value (may be empty)
     */
    public static function resolveBasePath(string $root, string $includes, string $appName): string
    {
        $path = $root . DIRECTORY_SEPARATOR . $includes;
        if ($appName !== '') {
            $path .= DIRECTORY_SEPARATOR . $appName;
        }
        return $path;
    }
}
