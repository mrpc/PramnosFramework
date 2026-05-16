<?php
namespace Pramnos\Auth;

/**
 * Thrown when a JWT token has expired (exp claim in the past).
 *
 * @package Pramnos\Auth
 */
class ExpiredException extends \Exception {}
