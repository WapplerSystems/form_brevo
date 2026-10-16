<?php

declare(strict_types=1);

namespace WapplerSystems\FormBrevo\Brevo;

/**
 * A failed Brevo call. $status is 0 if no HTTP answer came back.
 * Brevo reports errors as {"code": "...", "message": "..."}.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $responseBody,
        int $code,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        $data = json_decode($this->responseBody, true);
        return is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : '';
    }

    public function errorMessage(): string
    {
        $data = json_decode($this->responseBody, true);
        return is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '';
    }

    /**
     * True if Brevo rejected the visitor's input rather than our request,
     * i.e. a different address would go through.
     */
    public function isRejectedEmail(): bool
    {
        return $this->status === 400
            && $this->errorCode() === 'invalid_parameter'
            && stripos($this->errorMessage(), 'email') !== false;
    }
}
