<?php

declare(strict_types=1);

namespace WapplerSystems\FormBrevo\Brevo;

use GuzzleHttp\Exception\RequestException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Minimal Brevo API v3 client for the contact endpoints the finisher needs.
 *
 * The API key comes from the extension configuration or, if that is empty,
 * from the environment variable BREVO_API_KEY, so it never has to live in a
 * form definition or in a repository.
 */
class Client
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Creates the contact or, with $updateEnabled, updates an existing one.
     * POST /contacts answers 201 for a new and 204 for an updated contact.
     *
     * @param array<string, mixed> $attributes
     * @param list<int> $listIds
     */
    public function createOrUpdateContact(string $email, array $attributes, array $listIds, bool $updateEnabled): void
    {
        $payload = ['email' => $email, 'updateEnabled' => $updateEnabled];
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }
        if ($listIds !== []) {
            $payload['listIds'] = $listIds;
        }
        $this->send('POST', '/contacts', $payload);
    }

    /**
     * Sends the double opt-in mail. Brevo adds the contact to the lists only
     * after the visitor clicked the link in that mail.
     *
     * @param array<string, mixed> $attributes
     * @param list<int> $listIds
     */
    public function requestDoubleOptIn(string $email, array $attributes, array $listIds, int $templateId, string $redirectionUrl): void
    {
        $payload = [
            'email' => $email,
            'includeListIds' => $listIds,
            'templateId' => $templateId,
            'redirectionUrl' => $redirectionUrl,
        ];
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }
        $this->send('POST', '/contacts/doubleOptinConfirmation', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException
     */
    private function send(string $method, string $path, array $payload): void
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new ApiException('No Brevo API key configured.', 0, '', 1760620801);
        }

        try {
            $response = $this->requestFactory->request($this->baseUrl() . $path, $method, [
                'headers' => [
                    'api-key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'timeout' => 10,
                // Guzzle would throw on 4xx/5xx; the status is evaluated below instead.
                'http_errors' => false,
            ]);
        } catch (RequestException $e) {
            throw new ApiException('Brevo API not reachable: ' . $e->getMessage(), 0, '', 1760620802, $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }
        $body = (string)$response->getBody();
        throw new ApiException('Brevo API answered ' . $status, $status, $body, 1760620803);
    }

    private function apiKey(): string
    {
        $key = trim((string)$this->setting('apiKey'));
        if ($key === '') {
            $key = trim((string)getenv('BREVO_API_KEY'));
        }
        return $key;
    }

    private function baseUrl(): string
    {
        $url = trim((string)$this->setting('apiBaseUrl'));
        return rtrim($url !== '' ? $url : 'https://api.brevo.com/v3', '/');
    }

    private function setting(string $name): mixed
    {
        try {
            return $this->extensionConfiguration->get('form_brevo', $name);
        } catch (\Throwable) {
            return null;
        }
    }
}
