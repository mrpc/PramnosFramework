<?php

namespace Pramnos\Validation;

class ValidationException extends \Exception
{
    /**
     * @var array<string, array<int, string>>
     */
    protected $errors = [];

    protected $status = 422;

    /**
     * @param array<string, array<int, string>> $errors
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        array $errors,
        string $message = 'The given data was invalid.',
        int $code = 422,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }
}