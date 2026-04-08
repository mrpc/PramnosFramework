<?php

namespace Pramnos\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Pramnos\Validation\ValidationException;

#[\PHPUnit\Framework\Attributes\CoversClass(ValidationException::class)]
class ValidationExceptionTest extends TestCase
{
    public function testExceptionBasics()
    {
        $errors = ['field' => ['error message']];
        $exception = new ValidationException($errors, 'Custom message', 400);

        $this->assertEquals($errors, $exception->errors());
        $this->assertEquals('Custom message', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());
        $this->assertEquals(422, $exception->getStatus()); // Default status is 422
    }

    public function testDefaultValues()
    {
        $errors = [];
        $exception = new ValidationException($errors);

        $this->assertEquals('The given data was invalid.', $exception->getMessage());
        $this->assertEquals(422, $exception->getCode());
        $this->assertEquals([], $exception->errors());
    }
}
