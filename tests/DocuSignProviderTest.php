<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign\Tests;

use LaSouris\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LaSouris\DocumentSigner\DocuSign\DocuSignConfig;
use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\DocuSign\Http\DocuSignClient;
use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderException;
use LaSouris\DocumentSigner\Sdk\Exception\SignedDocumentUnavailableException;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use LaSouris\DocumentSigner\Sdk\Signer\SigningOrder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignProviderTest extends TestCase
{
    private static ?string $privateKey = null;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            self::markTestSkipped('openssl_pkey_new failed; skipping provider tests.');
        }
        openssl_pkey_export($resource, $pem);
        self::$privateKey = $pem;
    }

    #[Test]
    public function send_uploads_base64_pdf_and_returns_receipt_with_provider_name(): void
    {
        $envelope = $this->envelopeWithOneSigner();

        [$provider, $history] = $this->buildProvider([
            // JWT exchange:
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            // Envelope create:
            new Response(201, [], json_encode(['envelopeId' => 'env-abc', 'status' => 'sent'])),
        ]);

        $receipt = $provider->send($envelope);

        self::assertSame(DocuSignProvider::NAME, $receipt->provider);
        self::assertSame('docusign', $receipt->provider);
        self::assertSame('env-abc', $receipt->providerEnvelopeId);
        self::assertSame(EnvelopeStatus::Sent, $receipt->status);

        self::assertCount(2, $history);
        $envelopeRequest = $history[1]['request'];
        self::assertSame('POST', $envelopeRequest->getMethod());
        self::assertStringContainsString('/envelopes', (string) $envelopeRequest->getUri());

        $payload = json_decode((string) $envelopeRequest->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sent', $payload['status']);
        self::assertSame('Please sign the NDA', $payload['emailSubject']);
        self::assertSame('Jane Doe', $payload['recipients']['signers'][0]['name']);
        self::assertSame(
            '**DS:signature:s1:sig**',
            $payload['recipients']['signers'][0]['tabs']['signHereTabs'][0]['anchorString'],
        );
        self::assertNotEmpty($payload['documents'][0]['documentBase64']);
        self::assertStringStartsWith('%PDF-FAKE', base64_decode($payload['documents'][0]['documentBase64']));

        // The caller-id map is stored as an ENVELOPE-level custom field (these
        // round-trip), NOT as inline per-document fields (DocuSign drops those).
        self::assertArrayNotHasKey('documentFields', $payload['documents'][0]);

        $mapField = null;
        foreach ($payload['customFields']['textCustomFields'] as $field) {
            if ($field['name'] === 'sdkDocumentMap') {
                $mapField = $field;
            }
        }
        self::assertNotNull($mapField, 'envelope custom fields must carry sdkDocumentMap');
        self::assertSame(['nda' => '1'], json_decode($mapField['value'], true));
    }

    #[Test]
    public function send_throws_when_response_lacks_envelope_id(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(201, [], json_encode(['noEnvelope' => true])),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('did not return an envelopeId');

        $provider->send($this->envelopeWithOneSigner());
    }

    #[Test]
    public function send_throws_a_validation_exception_with_the_provider_message_for_400_responses(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(400, [], json_encode([
                'errorCode' => 'INVALID_EMAIL_ADDRESS',
                'message'   => 'The email address is invalid.',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderValidationException.');
        } catch (\LaSouris\DocumentSigner\Sdk\Exception\ProviderValidationException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('INVALID_EMAIL_ADDRESS', $e->providerCode);
            self::assertSame('The email address is invalid.', $e->providerMessage);
            self::assertStringContainsString('[400 INVALID_EMAIL_ADDRESS]', $e->getMessage());
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function send_carries_the_envelope_id_when_the_error_body_echoes_one(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(409, [], json_encode([
                'errorCode'  => 'ENVELOPE_ALREADY_SENT',
                'message'    => 'The envelope is already in the sent state.',
                'envelopeId' => 'env-echo-42',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderValidationException.');
        } catch (\LaSouris\DocumentSigner\Sdk\Exception\ProviderValidationException $e) {
            self::assertSame('env-echo-42', $e->providerEnvelopeId);
        }
    }

    #[Test]
    public function send_throws_an_authentication_exception_for_401_responses(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(401, [], json_encode([
                'errorCode' => 'AUTHORIZATION_INVALID_TOKEN',
                'message'   => 'The access token is missing or invalid.',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderAuthenticationException.');
        } catch (\LaSouris\DocumentSigner\Sdk\Exception\ProviderAuthenticationException $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame('AUTHORIZATION_INVALID_TOKEN', $e->providerCode);
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function get_status_maps_provider_status_strings(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['status' => 'completed'])),
            new Response(200, [], json_encode(['status' => 'voided'])),
            new Response(200, [], json_encode(['status' => 'declined'])),
            new Response(200, [], json_encode(['status' => 'mystery'])),
        ]);

        self::assertSame(EnvelopeStatus::Completed, $provider->getStatus('e1'));
        self::assertSame(EnvelopeStatus::Voided,    $provider->getStatus('e2'));
        self::assertSame(EnvelopeStatus::Declined,  $provider->getStatus('e3'));
        self::assertSame(EnvelopeStatus::Unknown,   $provider->getStatus('e4'));
    }

    #[Test]
    public function download_signed_returns_the_archive_zip(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], 'PK-FAKE-ZIP-BYTES'),
        ]);

        $file = $provider->downloadSigned('env-42');

        self::assertInstanceOf(\SplFileInfo::class, $file);
        self::assertSame('zip', $file->getExtension());
        self::assertSame('PK-FAKE-ZIP-BYTES', file_get_contents($file->getPathname()));

        self::assertStringContainsString(
            '/envelopes/env-42/documents/archive',
            (string) $history[1]['request']->getUri(),
        );

        @unlink($file->getPathname());
    }

    #[Test]
    public function download_signed_document_resolves_the_caller_id_via_the_envelope_custom_field_map(): void
    {
        // Caller asks for their own id 'C-2607-WPNB-sepa' (which is NOT the
        // document name); it maps to positional '2' via the envelope custom field.
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode($this->customFieldsBody([
                'O-2607-JZXK-offer' => '1',
                'C-2607-WPNB-sepa'  => '2',
            ]))),
            new Response(200, [], '%PDF-SIGNED-SEPA'),
        ]);

        $file = $provider->downloadSignedDocument('env-42', 'C-2607-WPNB-sepa');

        self::assertSame('pdf', $file->getExtension());
        self::assertSame('%PDF-SIGNED-SEPA', file_get_contents($file->getPathname()));

        // It read the envelope custom fields (the map), then fetched positional
        // '2' directly — no document-list call needed.
        self::assertStringContainsString('/envelopes/env-42/custom_fields', (string) $history[1]['request']->getUri());
        self::assertStringContainsString('/envelopes/env-42/documents/2', (string) $history[2]['request']->getUri());
        self::assertSame('application/pdf', $history[2]['request']->getHeaderLine('Accept'));

        @unlink($file->getPathname());
    }

    #[Test]
    public function download_signed_document_does_not_rely_on_inline_document_fields(): void
    {
        // Regression for the v2.3.0 bug: DocuSign silently drops inline
        // documents[].documentFields, so they can never be read back. Resolution
        // must come from the round-tripping envelope custom-field map alone —
        // the document list (which has no documentFields) is not even consulted
        // on the happy path.
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode($this->customFieldsBody(['O-2607-JZXK-offer' => '1']))),
            new Response(200, [], '%PDF-SIGNED-OFFER'),
        ]);

        $file = $provider->downloadSignedDocument('env-42', 'O-2607-JZXK-offer');

        self::assertSame('%PDF-SIGNED-OFFER', file_get_contents($file->getPathname()));

        // Exactly three requests: auth, custom_fields, single-document. No
        // /documents list call and no dependency on per-document fields.
        self::assertCount(3, $history);
        self::assertStringContainsString('/envelopes/env-42/custom_fields', (string) $history[1]['request']->getUri());
        self::assertStringContainsString('/envelopes/env-42/documents/1', (string) $history[2]['request']->getUri());

        @unlink($file->getPathname());
    }

    #[Test]
    public function download_signed_document_falls_back_to_the_normalized_name(): void
    {
        // Older envelope: no map in custom fields. The caller passes a name whose
        // spacing/casing differs from DocuSign's stored, underscore-mangled name.
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode($this->customFieldsBody([]))),      // no map
            new Response(200, [], json_encode([
                'envelopeDocuments' => [
                    ['documentId' => '1', 'name' => 'NDA', 'type' => 'content'],
                    ['documentId' => '2', 'name' => 'SEPA_machtiging_C-1', 'type' => 'content'],
                    ['documentId' => 'certificate', 'name' => 'Summary', 'type' => 'summary'],
                ],
            ])),
            new Response(200, [], '%PDF-SIGNED-SEPA'),
        ]);

        $file = $provider->downloadSignedDocument('env-42', 'sepa machtiging c-1');

        self::assertSame('%PDF-SIGNED-SEPA', file_get_contents($file->getPathname()));
        self::assertStringContainsString('/envelopes/env-42/documents/2', (string) $history[3]['request']->getUri());

        @unlink($file->getPathname());
    }

    #[Test]
    public function download_signed_document_ignores_the_certificate_and_throws_when_unresolved(): void
    {
        // No map, and the only name-matchable entry is the Summary certificate,
        // which must be skipped — so this resolves to nothing.
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode($this->customFieldsBody([]))),
            new Response(200, [], json_encode([
                'envelopeDocuments' => [
                    ['documentId' => '1', 'name' => 'NDA', 'type' => 'content'],
                    ['documentId' => 'certificate', 'name' => 'Summary', 'type' => 'summary'],
                ],
            ])),
        ]);

        try {
            $provider->downloadSignedDocument('env-42', 'Summary');
            self::fail('Expected SignedDocumentUnavailableException.');
        } catch (SignedDocumentUnavailableException $e) {
            self::assertTrue($e->isRetryable());
            self::assertSame('env-42', $e->providerEnvelopeId);
            self::assertStringContainsString('Summary', $e->getMessage());
        }
    }

    /**
     * A DocuSign custom-fields response carrying the SDK's document-id map.
     *
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    private function customFieldsBody(array $map): array
    {
        return [
            'textCustomFields' => [
                ['name' => 'tenant', 'value' => 'acme'],
                ['name' => 'sdkDocumentMap', 'value' => json_encode($map)],
            ],
        ];
    }

    #[Test]
    public function has_audit_trail_is_true_because_docusign_ships_the_audit_events_feed(): void
    {
        [$provider] = $this->buildProvider([]);

        self::assertTrue($provider->hasAuditTrail());
    }

    #[Test]
    public function download_audit_returns_the_certificate_of_completion_pdf(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], '%PDF-CERTIFICATE-OF-COMPLETION'),
        ]);

        $file = $provider->downloadAudit('env-42');

        // The human-readable evidence PDF (analog of ValidSign's Evidence
        // Summary), not the raw audit-events JSON.
        self::assertSame('pdf', $file->getExtension());
        self::assertSame('%PDF-CERTIFICATE-OF-COMPLETION', file_get_contents($file->getPathname()));

        self::assertStringContainsString(
            '/envelopes/env-42/documents/certificate',
            (string) $history[1]['request']->getUri(),
        );
        self::assertSame('application/pdf', $history[1]['request']->getHeaderLine('Accept'));

        @unlink($file->getPathname());
    }

    /**
     * @param array<int, Response> $responses
     * @return array{0: DocuSignProvider, 1: \ArrayObject<int, array<string, mixed>>}
     */
    private function buildProvider(array $responses, int $anchorYOffsetPixels = 0): array
    {
        $mock = new MockHandler($responses);
        $history = new \ArrayObject();
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        $config = new DocuSignConfig(
            integrationKey: 'k', userId: 'u', accountId: 'a',
            privateKey:     (string) self::$privateKey,
            anchorYOffsetPixels: $anchorYOffsetPixels,
        );

        $auth = new DocuSignJwtAuth($config, $http);
        $client = new DocuSignClient($config, $auth, $http);

        $provider = new DocuSignProvider(
            $config,
            client: $client,
            pdfRenderer: $this->fakePdfRenderer(),
        );

        return [$provider, $history];
    }

    #[Test]
    public function get_field_values_returns_tab_values_from_the_recipients_endpoint(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode([
                'signers' => [
                    [
                        'recipientId' => '1',
                        'tabs' => [
                            'textTabs' => [
                                [
                                    'documentId' => '1',
                                    'tabLabel'   => 'iban',
                                    'value'      => 'NL91ABNA0417164300',
                                ],
                                [
                                    'documentId' => '1',
                                    'tabLabel'   => 'fullname',
                                    'value'      => 'Jane Doe',
                                ],
                                [
                                    // Optional field left blank
                                    'documentId' => '1',
                                    'tabLabel'   => 'phone',
                                    'value'      => '',
                                ],
                            ],
                            'checkboxTabs' => [
                                [
                                    'documentId' => '1',
                                    'tabLabel'   => 'opt_in',
                                    'selected'   => 'true',
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ]);

        $values = $provider->getFieldValues('env-42');

        self::assertStringContainsString(
            '/envelopes/env-42/recipients?include_tabs=true',
            (string) $history[1]['request']->getUri(),
        );
        self::assertCount(4, $values);

        $byName = array_column($values, null, 'fieldName');
        self::assertSame('NL91ABNA0417164300', $byName['iban']->value);
        self::assertSame('Jane Doe', $byName['fullname']->value);
        self::assertNull($byName['phone']->value, 'empty string is normalised to null');
        self::assertSame('true', $byName['opt_in']->value);
        self::assertSame('1', $byName['iban']->documentId);
        self::assertSame('1', $byName['iban']->signerKey);
    }

    #[Test]
    public function tabs_are_offset_so_their_top_edge_lands_on_the_anchor(): void
    {
        $envelope = new Envelope(
            name:         'Mix',
            documents:    [new Document(
                id:   'doc',
                name: 'Doc',
                html: '<p>{[signature:s1:sig]}{[text:s1:note]}{[date:s1:when]}{[initials:s1:par]}</p>',
            )],
            signers:      [new Signer('s1', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'Please sign',
        );

        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(201, [], json_encode(['envelopeId' => 'env-1', 'status' => 'sent'])),
        ]);

        $provider->send($envelope);

        $tabs = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)
            ['recipients']['signers'][0]['tabs'];

        // Adornment tabs use measured constants; field tabs use half their height.
        // All values verified against the live DocuSign API (top edge on anchor).
        self::assertSame('31', $tabs['signHereTabs'][0]['anchorYOffset']);
        self::assertSame('pixels', $tabs['signHereTabs'][0]['anchorUnits']);
        self::assertSame(200, $tabs['signHereTabs'][0]['width']);
        self::assertSame(50, $tabs['signHereTabs'][0]['height']);
        self::assertSame('36', $tabs['initialHereTabs'][0]['anchorYOffset']);
        self::assertSame('9', $tabs['textTabs'][0]['anchorYOffset']);   // 18 / 2
        self::assertSame('10', $tabs['dateSignedTabs'][0]['anchorYOffset']); // 20 / 2
    }

    #[Test]
    public function the_anchor_y_offset_pixels_config_nudges_every_tab(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(201, [], json_encode(['envelopeId' => 'env-1', 'status' => 'sent'])),
        ], anchorYOffsetPixels: 8);

        $provider->send($this->envelopeWithOneSigner());

        $tabs = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)
            ['recipients']['signers'][0]['tabs'];

        // Signature base offset (31) + configured nudge (8).
        self::assertSame('39', $tabs['signHereTabs'][0]['anchorYOffset']);
    }

    private function envelopeWithOneSigner(): Envelope
    {
        return new Envelope(
            name:         'NDA',
            documents:    [new Document(
                id:   'nda',
                name: 'NDA',
                html: '<p>Sign: {[signature:s1:sig]}</p>',
            )],
            signers:      [new Signer('s1', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'Please sign the NDA',
            signingOrder: SigningOrder::Parallel,
        );
    }

    #[Test]
    public function required_flag_on_text_and_checkbox_tabs_is_threaded_through(): void
    {
        $envelope = new Envelope(
            name:         'NDA',
            documents:    [new Document(
                id:   'nda',
                name: 'NDA',
                html: '<p>{[text:s1:required_notes]}{[?text:s1:optional_notes]}'
                    . '{[checkbox:s1:required_agree]}{[?checkbox:s1:optional_marketing]}</p>',
            )],
            signers:      [new Signer('s1', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'Please sign the NDA',
        );

        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(201, [], json_encode(['envelopeId' => 'env-xyz', 'status' => 'sent'])),
        ]);

        $provider->send($envelope);

        $payload = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $tabs = $payload['recipients']['signers'][0]['tabs'];

        $textByLabel = array_column($tabs['textTabs'], null, 'tabLabel');
        self::assertSame('true',  $textByLabel['required_notes']['required']);
        self::assertSame('false', $textByLabel['optional_notes']['required']);

        $checkboxByLabel = array_column($tabs['checkboxTabs'], null, 'tabLabel');
        self::assertSame('true',  $checkboxByLabel['required_agree']['required']);
        self::assertSame('false', $checkboxByLabel['optional_marketing']['required']);
    }

    private function fakePdfRenderer(): PdfRenderer
    {
        return new class implements PdfRenderer {
            public function render(string $html, ?\LaSouris\DocumentSigner\Sdk\Pdf\PageDecoration $decoration = null): string
            {
                return '%PDF-FAKE' . $html;
            }
        };
    }
}
