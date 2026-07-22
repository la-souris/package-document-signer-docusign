<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign;

final readonly class DocuSignConfig
{
    /**
     * @param string $integrationKey Integration (client) key from the DocuSign admin app.
     * @param string $userId         Impersonated user GUID (the API user that signs the JWT on behalf of).
     * @param string $accountId      DocuSign account id (GUID).
     * @param string $privateKey     RSA private key (PEM contents) paired with the integration key.
     * @param string $oauthBaseUrl   `account.docusign.com` (prod) or `account-d.docusign.com` (demo).
     * @param string $apiBaseUrl     REST API base, e.g. `https://na3.docusign.net/restapi` (prod) or `https://demo.docusign.net/restapi` (demo).
     * @param string $scopes         OAuth scopes to request when minting the JWT bearer assertion.
     * @param int    $accessTokenTtlSeconds JWT validity window; DocuSign maxes out around 3600.
     * @param int    $timeoutSeconds HTTP timeout for non-upload requests.
     * @param int    $uploadTimeoutSeconds  HTTP timeout for envelope create / file upload.
     * @param int    $anchorYOffsetPixels   Uniform vertical nudge (in pixels) added to every tab's
     *                                       `anchorYOffset`. Positive moves fields DOWN the page,
     *                                       negative UP. The SDK already offsets each tab so its top
     *                                       edge lands on the anchor (matching ValidSign); use this
     *                                       only to fine-tune any residual, document-wide vertical
     *                                       drift. Default 0.
     */
    public function __construct(
        public string $integrationKey,
        public string $userId,
        public string $accountId,
        public string $privateKey,
        public string $oauthBaseUrl  = 'account-d.docusign.com',
        public string $apiBaseUrl    = 'https://demo.docusign.net/restapi',
        public string $scopes        = 'signature impersonation',
        public int    $accessTokenTtlSeconds = 3600,
        public int    $timeoutSeconds        = 15,
        public int    $uploadTimeoutSeconds  = 60,
        public int    $anchorYOffsetPixels   = 0,
    ) {
        if ($integrationKey === '') {
            throw new \InvalidArgumentException('DocuSign integrationKey must be non-empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('DocuSign userId must be non-empty.');
        }
        if ($accountId === '') {
            throw new \InvalidArgumentException('DocuSign accountId must be non-empty.');
        }
        if (!str_contains($privateKey, 'PRIVATE KEY')) {
            throw new \InvalidArgumentException('DocuSign privateKey must be a PEM-encoded RSA private key.');
        }
        if (!preg_match('#^https?://#i', $apiBaseUrl)) {
            throw new \InvalidArgumentException("DocuSign apiBaseUrl must be a full http(s) URL, got: '{$apiBaseUrl}'");
        }
    }

    public function trimmedApiBaseUrl(): string
    {
        return rtrim($this->apiBaseUrl, '/');
    }
}
