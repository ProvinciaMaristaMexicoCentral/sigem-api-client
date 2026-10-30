<?php

namespace Maristas\SigemApi\Exceptions;

class SigemValidationException extends SigemException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message = 'Datos invalidos para la API de SIGEM.',
        protected array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 422, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
