<?php

namespace Maristas\SigemApi\Auth;

use Maristas\SigemApi\Exceptions\SigemProfileException;

/**
 * Perfil básico validado del endpoint userinfo.
 *
 * El id se normaliza a string. Se toleran atributos adicionales en raw.
 */
final class SigemUserProfile
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $username,
        public readonly ?string $emailVerifiedAt,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;
        $username = $data['username'] ?? null;
        $verifiedAt = $data['email_verified_at'] ?? null;

        if (is_int($id)) {
            $id = (string) $id;
        }

        if (! is_string($id) || $id === '') {
            throw new SigemProfileException('Perfil de SIGEM inválido.');
        }

        if (! is_string($name) || trim($name) === '') {
            throw new SigemProfileException('Perfil de SIGEM inválido.');
        }

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SigemProfileException('Perfil de SIGEM inválido.');
        }

        if ($username !== null && ! is_string($username)) {
            throw new SigemProfileException('Perfil de SIGEM inválido.');
        }

        if ($verifiedAt !== null && ! is_string($verifiedAt)) {
            throw new SigemProfileException('Perfil de SIGEM inválido.');
        }

        return new self($id, $name, $email, $username, $verifiedAt, $data);
    }
}
