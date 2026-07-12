<?php

namespace Pramnos\Support;

/**
 * Abstract base for all Faker providers.
 *
 * Concrete providers extend this class and expose public generator methods.
 * Methods that compose other generator methods must dispatch through
 * $this->generator so that overrides in subclasses are honoured.
 *
 */
abstract class FakerProvider
{
    public function __construct(protected Faker $generator) {}
}
