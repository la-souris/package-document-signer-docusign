<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign\Tests;

use LaSouris\DocumentSigner\DocuSign\DocuSignConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignConfigTest extends TestCase
{
    private const FAKE_PEM = "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----";

    #[Test]
    public function it_accepts_a_complete_config(): void
    {
        $config = new DocuSignConfig(
            integrationKey: 'k',
            userId:         'u',
            accountId:      'a',
            privateKey:     self::FAKE_PEM,
            apiBaseUrl:     'https://demo.docusign.net/restapi/',
        );

        self::assertSame('k', $config->integrationKey);
        self::assertSame('https://demo.docusign.net/restapi', $config->trimmedApiBaseUrl());
    }

    #[Test]
    public function it_rejects_empty_integration_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocuSignConfig(integrationKey: '', userId: 'u', accountId: 'a', privateKey: self::FAKE_PEM);
    }

    #[Test]
    public function it_rejects_non_pem_private_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocuSignConfig(integrationKey: 'k', userId: 'u', accountId: 'a', privateKey: 'not-a-pem');
    }

    #[Test]
    public function it_rejects_non_http_api_base_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocuSignConfig(
            integrationKey: 'k', userId: 'u', accountId: 'a',
            privateKey: self::FAKE_PEM,
            apiBaseUrl: 'ftp://example',
        );
    }
}
