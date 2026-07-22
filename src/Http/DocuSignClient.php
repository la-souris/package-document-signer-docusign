<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign\Http;

use LaSouris\DocumentSigner\Sdk\Exception\ProviderException;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderTransientException;
use LaSouris\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LaSouris\DocumentSigner\DocuSign\DocuSignConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

final class DocuSignClient
{
    private ClientInterface $http;

    public function __construct(
        private readonly DocuSignConfig $config,
        private readonly DocuSignJwtAuth $auth,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => $this->config->trimmedApiBaseUrl() . '/',
            'timeout'  => $this->config->timeoutSeconds,
        ]);
    }

    /**
     * @param array<string, mixed> $payload Full envelope definition with embedded base64 documents.
     * @return array<string, mixed>
     */
    public function createEnvelope(array $payload): array
    {
        return $this->jsonRequest('POST', $this->accountPath('envelopes'), [
            'json'    => $payload,
            'timeout' => $this->config->uploadTimeoutSeconds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnvelope(string $envelopeId): array
    {
        return $this->jsonRequest('GET', $this->accountPath('envelopes/' . rawurlencode($envelopeId)));
    }

    public function downloadSignedArchive(string $envelopeId): string
    {
        return $this->rawRequest(
            'GET',
            $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/documents/archive'),
            ['headers' => ['Accept' => 'application/zip']],
        );
    }

    /**
     * List the envelope's documents — each carries DocuSign's positional
     * `documentId` and `name`. The response also includes the
     * certificate-of-completion as a synthetic entry whose `documentId` is
     * `"certificate"`. Used only for name-based fallback resolution.
     *
     * @return array<string, mixed>
     */
    public function listDocuments(string $envelopeId): array
    {
        return $this->jsonRequest(
            'GET',
            $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/documents'),
        );
    }

    /**
     * The envelope's custom fields (`textCustomFields` / `listCustomFields`).
     * Unlike inline per-document fields at creation, these round-trip reliably,
     * so we read the SDK's `Document::$id => positional id` map back from here.
     *
     * @return array<string, mixed>
     */
    public function getEnvelopeCustomFields(string $envelopeId): array
    {
        return $this->jsonRequest(
            'GET',
            $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/custom_fields'),
        );
    }

    public function downloadSignedDocument(string $envelopeId, string $documentId): string
    {
        return $this->rawRequest(
            'GET',
            $this->accountPath(
                'envelopes/' . rawurlencode($envelopeId) . '/documents/' . rawurlencode($documentId),
            ),
            ['headers' => ['Accept' => 'application/pdf']],
        );
    }

    /**
     * The Certificate of Completion PDF — DocuSign's human-readable evidence
     * report (signer identities, timestamps, IP addresses, auth methods). This
     * is the analog of ValidSign's Evidence Summary Report; `documentId` is the
     * reserved literal `certificate`.
     */
    public function downloadCertificateOfCompletion(string $envelopeId): string
    {
        return $this->rawRequest(
            'GET',
            $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/documents/certificate'),
            ['headers' => ['Accept' => 'application/pdf']],
        );
    }

    /**
     * The granular, machine-readable envelope audit-events feed (JSON). Not what
     * {@see \LaSouris\DocumentSigner\DocuSign\DocuSignProvider::downloadAudit()}
     * returns (that serves the Certificate of Completion PDF for cross-provider
     * consistency) — call this directly on the client when you need the events.
     */
    public function downloadAuditEventsJson(string $envelopeId): string
    {
        return $this->rawRequest(
            'GET',
            $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/audit_events'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormData(string $envelopeId): array
    {
        return $this->jsonRequest('GET', $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/form_data'));
    }

    /**
     * Recipients + their tabs (with filled-in `value`s after signing). We use
     * this instead of `/form_data` because it exposes `documentId` per tab, so
     * we can group field values by document like ValidSign's `/fieldSummary` does.
     *
     * @return array<string, mixed>
     */
    public function getRecipientsWithTabs(string $envelopeId): array
    {
        return $this->jsonRequest(
            'GET',
            $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/recipients?include_tabs=true'),
        );
    }

    public function voidEnvelope(string $envelopeId, ?string $reason): void
    {
        $this->jsonRequest('PUT', $this->accountPath('envelopes/' . rawurlencode($envelopeId)), [
            'json' => [
                'status'       => 'voided',
                'voidedReason' => $reason ?? 'Cancelled via SDK',
            ],
        ]);
    }

    private function accountPath(string $tail): string
    {
        return 'v2.1/accounts/' . rawurlencode($this->config->accountId) . '/' . $tail;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function jsonRequest(string $method, string $path, array $options = []): array
    {
        $body = $this->rawRequest($method, $path, $options);
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException(
                "DocuSign returned non-JSON response for {$method} {$path}.",
                providerBody: $body,
                previous: $e,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function rawRequest(string $method, string $path, array $options = []): string
    {
        $options['headers'] = array_merge(
            ['Accept' => 'application/json'],
            $options['headers'] ?? [],
            ['Authorization' => 'Bearer ' . $this->auth->accessToken()],
        );

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (RequestException $e) {
            throw $this->translateHttpError($method, $path, $e);
        } catch (GuzzleException $e) {
            throw new ProviderTransientException(
                message: sprintf('DocuSign %s /%s transport error: %s', $method, ltrim($path, '/'), $e->getMessage()),
                previous: $e,
            );
        }

        return (string) $response->getBody();
    }

    private function translateHttpError(string $method, string $path, RequestException $e): ProviderException
    {
        $response = $e->getResponse();
        $status = $response?->getStatusCode();
        $body = $response?->getBody()?->getContents();

        [$providerCode, $providerMessage, $envelopeId] = $this->parseErrorPayload($body);

        if ($status === null) {
            return new ProviderTransientException(
                message: sprintf('DocuSign %s /%s transport error: %s', $method, ltrim($path, '/'), $e->getMessage()),
                providerBody: $body,
                previous: $e,
                providerEnvelopeId: $envelopeId,
            );
        }

        return ProviderException::fromHttpStatus(
            providerName: 'DocuSign',
            method: $method,
            path: $path,
            status: $status,
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            providerBody: $body,
            previous: $e,
            retryAfterSeconds: $this->parseRetryAfter($response?->getHeaderLine('Retry-After')),
            providerEnvelopeId: $envelopeId,
        );
    }

    /**
     * DocuSign error responses look like `{ "errorCode": "INVALID_EMAIL_ADDRESS", "message": "..." }`;
     * post-creation failures (rare, but possible on multi-step operations) can also include an
     * `envelopeId` field pointing at the already-created envelope.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} Tuple of (providerCode, providerMessage, providerEnvelopeId).
     */
    private function parseErrorPayload(?string $body): array
    {
        if (!is_string($body) || $body === '') {
            return [null, null, null];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, null, null];
        }

        if (!is_array($decoded)) {
            return [null, null, null];
        }

        $code = is_string($decoded['errorCode'] ?? null) ? $decoded['errorCode'] : null;
        $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : null;
        $envelopeId = is_string($decoded['envelopeId'] ?? null) && $decoded['envelopeId'] !== ''
            ? $decoded['envelopeId']
            : null;

        return [$code, $message, $envelopeId];
    }

    private function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }
        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }
        return max(0, $timestamp - time());
    }
}
