<?php

namespace Pramnos\Policy;

/**
 * Immutable value object representing a single row from the framework_policies
 * table.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Policy
 */
class PolicyRecord
{
    public function __construct(
        public readonly int     $policyid,
        public readonly string  $policyType,
        public readonly string  $target,
        public readonly array   $config,
        public readonly bool    $enabled,
        public readonly ?string $lastRun,
        public readonly ?string $nextRun,
        public readonly ?string $lastResult,
        public readonly ?string $lastError,
        public readonly string  $createdAt
    ) {
    }

    /**
     * Constructs a PolicyRecord from a raw database row (associative array).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $config = $row['config'] ?? '{}';
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        return new self(
            policyid:   (int)    ($row['policyid']    ?? 0),
            policyType: (string) ($row['policy_type'] ?? ''),
            target:     (string) ($row['target']      ?? ''),
            config:     (array)  $config,
            enabled:    (bool)   ($row['enabled']     ?? true),
            lastRun:    isset($row['last_run'])    ? (string) $row['last_run']    : null,
            nextRun:    isset($row['next_run'])    ? (string) $row['next_run']    : null,
            lastResult: isset($row['last_result']) ? (string) $row['last_result'] : null,
            lastError:  isset($row['last_error'])  ? (string) $row['last_error']  : null,
            createdAt:  (string) ($row['created_at'] ?? '')
        );
    }
}
