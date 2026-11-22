<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Pričakovana napaka z znano kodo iz docs/API.md.
 *
 * Service jo vrže, Kernel jo prevede v JSON. Sporočilo je namenjeno
 * odjemalcu (osebju ali modelu), zato ne sme vsebovati notranjih podrobnosti.
 */
class AppException extends RuntimeException
{
    /**
     * @param array<string, string> $fields napake po poljih (invalid_input)
     * @param array<string, mixed>  $extra  dodatni podatki v odgovoru (npr. alternative)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $fields = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $fields */
    public static function invalid(array $fields, string $message = 'Neveljaven vnos.'): self
    {
        return new self('invalid_input', $message, 400, $fields);
    }

    /** Neobstoječ ali tuj zapis. Namerno enak odgovor. */
    public static function notFound(): self
    {
        return new self('not_found', 'Ne obstaja.', 404);
    }

    public static function rule(string $code, string $message): self
    {
        return new self($code, $message, 422);
    }

    /** @param list<array<string, mixed>> $alternatives */
    public static function slotTaken(array $alternatives = []): self
    {
        return new self('slot_taken', 'Termin je zaseden.', 409, [], ['alternatives' => $alternatives]);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }
}
