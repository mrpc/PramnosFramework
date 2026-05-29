<?php

namespace Pramnos\Health;

/**
 * Immutable result returned by a single HealthCheck execution.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class HealthCheckResult
{
    /**
     * @param HealthStatus         $status  Overall status of this check.
     * @param string               $name    Human-readable check name.
     * @param string               $message Short description of the result.
     * @param array<string, mixed> $details Optional key→value diagnostic data.
     */
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string       $name,
        public readonly string       $message,
        public readonly array        $details = []
    ) {
    }

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    public static function ok(string $name, string $message = '', array $details = []): self
    {
        return new self(HealthStatus::Ok, $name, $message ?: 'OK', $details);
    }

    public static function degraded(string $name, string $message, array $details = []): self
    {
        return new self(HealthStatus::Degraded, $name, $message, $details);
    }

    public static function down(string $name, string $message, array $details = []): self
    {
        return new self(HealthStatus::Down, $name, $message, $details);
    }

    // -------------------------------------------------------------------------
    // Serialisation
    // -------------------------------------------------------------------------

    /**
     * Returns a plain array representation suitable for JSON encoding.
     *
     * @return array{status: string, name: string, message: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status'  => $this->status->value,
            'name'    => $this->name,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
