<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign;

use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderException;
use LaSouris\DocumentSigner\Sdk\Exception\SignedDocumentUnavailableException;
use LaSouris\DocumentSigner\Sdk\Field\FieldType;
use LaSouris\DocumentSigner\Sdk\Pdf\BrowsershotPdfRenderer;
use LaSouris\DocumentSigner\Sdk\Pdf\PageDecoration;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Placeholder\PlaceholderParser;
use LaSouris\DocumentSigner\Sdk\Placeholder\PreparedField;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\FieldValue;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use LaSouris\DocumentSigner\Sdk\Signer\SigningOrder;
use LaSouris\DocumentSigner\Sdk\Support\TempFile;
use LaSouris\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LaSouris\DocumentSigner\DocuSign\Http\DocuSignClient;
use LaSouris\DocumentSigner\DocuSign\Placeholder\DocuSignPlaceholderReplacer;

final class DocuSignProvider implements SignatureProvider
{
    public const string NAME = 'docusign';

    /**
     * Name of the DocuSign **envelope-level** text custom field that stores a
     * JSON map of `Document::$id => DocuSign positional documentId`. Written on
     * `send()`, read back by `downloadSignedDocument()` to resolve the caller's
     * own id.
     *
     * Envelope custom fields are used deliberately: unlike per-document
     * `documents[].documentFields` supplied inline at envelope creation — which
     * DocuSign silently drops — envelope custom fields survive a
     * create → complete → read round-trip.
     */
    private const string DOCUMENT_MAP_FIELD = 'sdkDocumentMap';

    private readonly DocuSignConfig $config;
    private readonly DocuSignClient $client;
    private readonly PdfRenderer $pdfRenderer;
    private readonly DocuSignPlaceholderReplacer $replacer;
    private readonly PlaceholderParser $parser;

    public function __construct(
        DocuSignConfig $config,
        ?DocuSignClient $client = null,
        ?PdfRenderer $pdfRenderer = null,
        ?DocuSignPlaceholderReplacer $replacer = null,
        ?PlaceholderParser $parser = null,
    ) {
        $this->config      = $config;
        $this->client      = $client      ?? new DocuSignClient($config, new DocuSignJwtAuth($config));
        $this->pdfRenderer = $pdfRenderer ?? new BrowsershotPdfRenderer();
        $this->replacer    = $replacer    ?? new DocuSignPlaceholderReplacer();
        $this->parser      = $parser      ?? new PlaceholderParser();
    }

    public function send(Envelope $envelope): EnvelopeReceipt
    {
        $signerIndex = $this->indexSigners($envelope);
        $apiDocuments = [];
        $tabsBySigner = array_fill_keys(array_keys($signerIndex), $this->emptyTabBuckets());

        $docNumber = 1;
        $documentIdMap = [];
        foreach ($envelope->documents as $document) {
            $prepared = $this->replacer->replace($document->html, $this->parser->parse($document->html));
            $this->assertFieldsResolvable($envelope, $document, $prepared->fields);

            $pdf = $this->pdfRenderer->render($prepared->html, new PageDecoration(
                headerHtml: $document->headerHtml,
                footerHtml: $document->footerHtml,
                headerPlacement: $document->headerPlacement,
                footerPlacement: $document->footerPlacement,
            ));
            $documentId = (string) $docNumber++;
            $documentIdMap[$document->id] = $documentId;

            $apiDocuments[] = [
                'documentBase64' => base64_encode($pdf),
                'name'           => $document->name,
                'fileExtension'  => 'pdf',
                'documentId'     => $documentId,
            ];

            foreach ($prepared->fields as $field) {
                $bucket = $this->bucketForFieldType($field->type);
                $tabsBySigner[$field->signerKey][$bucket][] = $this->buildTab($documentId, $field);
            }
        }

        $signers = [];
        foreach ($envelope->signers as $signer) {
            $signers[] = $this->buildSigner(
                $signer,
                $signerIndex[$signer->key],
                $envelope->signingOrder,
                $tabsBySigner[$signer->key],
            );
        }

        $payload = [
            'emailSubject' => $envelope->emailSubject,
            'emailBlurb'   => $envelope->emailMessage ?? '',
            'status'       => 'sent',
            'documents'    => $apiDocuments,
            'recipients'   => ['signers' => $signers],
        ];

        if ($envelope->expiresAt !== null) {
            $payload['notification'] = [
                'expirations' => [
                    'expireEnabled' => 'true',
                    'expireAfter'   => max(1, (int) ceil(
                        ($envelope->expiresAt->getTimestamp() - time()) / 86400
                    )),
                ],
            ];
        }

        // Envelope-level custom fields: the caller's metadata, plus our own
        // document-id map. The map is what makes downloadSignedDocument() work —
        // it round-trips where inline per-document fields do not.
        $textCustomFields = $this->buildCustomFields($envelope->metadata);
        $textCustomFields[] = [
            'name'     => self::DOCUMENT_MAP_FIELD,
            'value'    => (string) json_encode($documentIdMap),
            'required' => 'false',
            'show'     => 'false',
        ];
        $payload['customFields'] = ['textCustomFields' => $textCustomFields];

        $response = $this->client->createEnvelope($payload);

        $envelopeId = $response['envelopeId'] ?? null;
        if (!is_string($envelopeId) || $envelopeId === '') {
            throw new ProviderException(
                'DocuSign did not return an envelopeId in the create-envelope response.',
                providerBody: json_encode($response),
            );
        }

        try {
            return new EnvelopeReceipt(
                provider: self::NAME,
                providerEnvelopeId: $envelopeId,
                status: $this->mapStatus($response['status'] ?? 'sent'),
                signerUrls: [],
                raw: $response,
            );
        } catch (ProviderException $e) {
            throw $e->withProviderEnvelopeId($envelopeId);
        } catch (\Throwable $e) {
            throw new ProviderException(
                message: 'DocuSign envelope was created but the SDK failed to build the receipt: ' . $e->getMessage(),
                previous: $e,
                providerEnvelopeId: $envelopeId,
            );
        }
    }

    public function getStatus(string $providerEnvelopeId): EnvelopeStatus
    {
        $response = $this->client->getEnvelope($providerEnvelopeId);
        return $this->mapStatus($response['status'] ?? null);
    }

    public function downloadSigned(string $providerEnvelopeId): \SplFileInfo
    {
        return TempFile::fromBytes(
            bytes: $this->client->downloadSignedArchive($providerEnvelopeId),
            prefix: 'docusign-signed-',
            extension: 'zip',
        );
    }

    public function downloadSignedDocument(string $providerEnvelopeId, string $documentId): \SplFileInfo
    {
        $positionalId = $this->resolveDocumentId($providerEnvelopeId, $documentId);

        return TempFile::fromBytes(
            bytes: $this->client->downloadSignedDocument($providerEnvelopeId, $positionalId),
            prefix: 'docusign-signed-doc-',
            extension: 'pdf',
        );
    }

    /**
     * Map the caller's {@see Document::$id} to DocuSign's positional document id.
     *
     * DocuSign never stores the caller's arbitrary id as its own `documentId`
     * (that must be a positional integer), and it silently drops per-document
     * fields supplied inline at creation. So we resolve in two steps:
     *
     *  1. Read the {@see DOCUMENT_MAP_FIELD} envelope custom field written on
     *     `send()` — a JSON `Document::$id => positional id` map. Envelope custom
     *     fields round-trip reliably, so this is the primary path for anything
     *     sent by this SDK.
     *  2. Fall back to matching the caller's value against a document's
     *     normalized `name` (case-folded, `[\s_]+` collapsed) — for envelopes
     *     sent before the map existed, or when the caller passes the document
     *     name. This only fetches the document list when step 1 misses.
     *
     * The certificate-of-completion entry (`documentId === "certificate"`, i.e.
     * the archive's `Summary.pdf`) is never returned — it belongs to no caller
     * document.
     *
     * @throws SignedDocumentUnavailableException When nothing matches (yet).
     */
    private function resolveDocumentId(string $providerEnvelopeId, string $documentId): string
    {
        // 1. Primary: the round-tripping envelope custom-field map.
        $map = $this->documentIdMap($providerEnvelopeId);
        $positionalId = $map[$documentId] ?? null;
        if (is_string($positionalId) && $positionalId !== '' && $positionalId !== 'certificate') {
            return $positionalId;
        }

        // 2. Fallback: normalized document-name match.
        $byName = $this->resolveByDocumentName($providerEnvelopeId, $documentId);
        if ($byName !== null) {
            return $byName;
        }

        throw SignedDocumentUnavailableException::for(
            providerName: 'DocuSign',
            providerEnvelopeId: $providerEnvelopeId,
            documentId: $documentId,
        );
    }

    /**
     * Read and decode the `Document::$id => positional id` map from the
     * envelope's custom fields. Returns `[]` when the field is absent (e.g. an
     * envelope sent before this SDK wrote it) or unparseable.
     *
     * @return array<string, string>
     */
    private function documentIdMap(string $providerEnvelopeId): array
    {
        $response = $this->client->getEnvelopeCustomFields($providerEnvelopeId);
        $fields = is_array($response['textCustomFields'] ?? null) ? $response['textCustomFields'] : [];

        foreach ($fields as $field) {
            if (!is_array($field) || ($field['name'] ?? null) !== self::DOCUMENT_MAP_FIELD) {
                continue;
            }

            $decoded = json_decode((string) ($field['value'] ?? ''), true);
            if (!is_array($decoded)) {
                return [];
            }

            $map = [];
            foreach ($decoded as $callerId => $positionalId) {
                if (is_string($callerId) && (is_string($positionalId) || is_int($positionalId))) {
                    $map[$callerId] = (string) $positionalId;
                }
            }

            return $map;
        }

        return [];
    }

    /**
     * Fallback resolution by normalized document name against the envelope's
     * document list, skipping the certificate. Returns the positional id, or
     * `null` when nothing matches.
     */
    private function resolveByDocumentName(string $providerEnvelopeId, string $documentId): ?string
    {
        $response = $this->client->listDocuments($providerEnvelopeId);
        $documents = is_array($response['envelopeDocuments'] ?? null) ? $response['envelopeDocuments'] : [];

        $wantedName = $this->normalizeDocumentName($documentId);

        foreach ($documents as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $positionalId = $entry['documentId'] ?? null;
            if (!is_string($positionalId) || $positionalId === '' || $positionalId === 'certificate') {
                continue; // skip the Summary.pdf certificate and malformed entries
            }

            if (is_string($entry['name'] ?? null)
                && $this->normalizeDocumentName($entry['name']) === $wantedName
            ) {
                return $positionalId;
            }
        }

        return null;
    }

    /**
     * Fold case and collapse every run of whitespace/underscores to a single
     * space, so "SEPA machtiging C-1", "SEPA_machtiging_C-1" and
     * "sepa  machtiging  c-1" all compare equal.
     */
    private function normalizeDocumentName(string $name): string
    {
        $collapsed = preg_replace('/[\s_]+/u', ' ', trim($name)) ?? $name;

        return mb_strtolower(trim($collapsed));
    }

    public function hasAuditTrail(): bool
    {
        return true;
    }

    public function downloadAudit(string $providerEnvelopeId): \SplFileInfo
    {
        // The Certificate of Completion PDF — DocuSign's human-readable evidence
        // report, the analog of ValidSign's Evidence Summary. (The granular
        // audit-events JSON is still available via
        // DocuSignClient::downloadAuditEventsJson().)
        return TempFile::fromBytes(
            bytes: $this->client->downloadCertificateOfCompletion($providerEnvelopeId),
            prefix: 'docusign-certificate-',
            extension: 'pdf',
        );
    }

    public function getFieldValues(string $providerEnvelopeId): array
    {
        $response = $this->client->getRecipientsWithTabs($providerEnvelopeId);

        $out = [];
        foreach (($response['signers'] ?? []) as $signer) {
            if (!is_array($signer)) {
                continue;
            }
            $signerId = is_string($signer['recipientId'] ?? null) ? $signer['recipientId'] : '';
            $tabs     = is_array($signer['tabs'] ?? null) ? $signer['tabs'] : [];

            // DocuSign exposes filled values on textTabs, checkboxTabs, dateSignedTabs,
            // fullNameTabs, emailTabs, etc. — we surface every tab with a `value` or
            // `selected` string, so callers can pull SEPA IBANs, opt-in checkboxes,
            // signed dates, etc.
            foreach (['textTabs', 'checkboxTabs', 'dateSignedTabs', 'listTabs', 'radioGroupTabs',
                      'ssnTabs', 'zipTabs', 'phoneTabs', 'emailTabs', 'firstNameTabs', 'lastNameTabs',
                      'fullNameTabs', 'titleTabs', 'companyTabs'] as $bucket) {
                foreach (($tabs[$bucket] ?? []) as $tab) {
                    if (!is_array($tab)) {
                        continue;
                    }
                    $value = $tab['value'] ?? ($tab['selected'] ?? null);
                    $out[] = new FieldValue(
                        documentId: is_string($tab['documentId'] ?? null) ? $tab['documentId'] : '',
                        signerKey:  $signerId,
                        fieldName:  is_string($tab['tabLabel'] ?? null) ? $tab['tabLabel'] : '',
                        value:      is_string($value) && $value !== '' ? $value : null,
                    );
                }
            }
        }

        return $out;
    }

    public function cancel(string $providerEnvelopeId, ?string $reason = null): void
    {
        $this->client->voidEnvelope($providerEnvelopeId, $reason);
    }

    private function mapStatus(mixed $status): EnvelopeStatus
    {
        $normalised = is_string($status) ? strtolower($status) : '';
        return match ($normalised) {
            'created'            => EnvelopeStatus::Draft,
            'sent'               => EnvelopeStatus::Sent,
            'delivered'          => EnvelopeStatus::Delivered,
            'completed', 'signed' => EnvelopeStatus::Completed,
            'declined'           => EnvelopeStatus::Declined,
            'voided'             => EnvelopeStatus::Voided,
            default              => EnvelopeStatus::Unknown,
        };
    }

    /**
     * @return array<string, int> Map of signer key → 1-based recipientId.
     */
    private function indexSigners(Envelope $envelope): array
    {
        $i = 1;
        $map = [];
        foreach ($envelope->signers as $signer) {
            $map[$signer->key] = $i++;
        }
        return $map;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $tabs
     * @return array<string, mixed>
     */
    private function buildSigner(Signer $signer, int $recipientId, SigningOrder $order, array $tabs): array
    {
        $routingOrder = $order === SigningOrder::Sequential ? (string) $signer->order : '1';

        return [
            'email'        => $signer->email,
            'name'         => $signer->name,
            'recipientId'  => (string) $recipientId,
            'routingOrder' => $routingOrder,
            'tabs'         => array_filter($tabs, static fn (array $bucket) => $bucket !== []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTab(string $documentId, PreparedField $field): array
    {
        [$width, $height] = $this->tabSize($field->type);

        // Without a vertical offset DocuSign seats an anchored tab too high. The
        // offset below drops each tab's TOP edge onto the anchor line — matching
        // where ValidSign's text-tags place their top-left, so the two providers
        // agree. `anchorYOffsetPixels` is a document-wide fine-tune on top.
        // (Positive anchorYOffset moves the tab down.)
        $tab = [
            'documentId'               => $documentId,
            'pageNumber'               => '1',
            'tabLabel'                 => $field->fieldName,
            'anchorString'             => $field->anchorString,
            'anchorXOffset'            => '0',
            'anchorYOffset'            => (string) ($this->anchorYOffset($field->type) + $this->config->anchorYOffsetPixels),
            'anchorUnits'              => 'pixels',
            'anchorIgnoreIfNotPresent' => 'false',
            'anchorCaseSensitive'      => 'true',
            'width'                    => $width,
            'height'                   => $height,
        ];

        // Signature / initials / dateSigned tabs are always required in DocuSign
        // and don't accept the `required` attribute. Text and Checkbox tabs do.
        if ($field->type === FieldType::Text || $field->type === FieldType::Checkbox) {
            $tab['required'] = $field->required ? 'true' : 'false';
        }

        return $tab;
    }

    /**
     * Nominal `[width, height]` per field type, in pixels — mirrors the sizes
     * ValidSign uses so the two providers place equally-sized fields.
     *
     * @return array{0: int, 1: int}
     */
    private function tabSize(FieldType $type): array
    {
        return match ($type) {
            FieldType::Signature => [200, 50],
            FieldType::Initials  => [100, 30],
            FieldType::Text      => [180, 18],
            FieldType::Date      => [120, 20],
            FieldType::Checkbox  => [20, 20],
        };
    }

    /**
     * Vertical anchor offset (px) that lands a tab's TOP edge on the anchor line,
     * measured against the live DocuSign API.
     *
     * Text / date / checkbox tabs render at the height we set, so half the height
     * centres the top on the anchor. Signature and initials render as fixed-size
     * "Sign Here" / "Initial Here" adornments whose placement is independent of
     * the field size, so they use measured constants rather than half-height.
     */
    private function anchorYOffset(FieldType $type): int
    {
        [, $height] = $this->tabSize($type);

        return match ($type) {
            FieldType::Signature => 31,
            FieldType::Initials  => 36,
            default              => intdiv($height, 2),
        };
    }

    private function bucketForFieldType(FieldType $type): string
    {
        return match ($type) {
            FieldType::Signature => 'signHereTabs',
            FieldType::Initials  => 'initialHereTabs',
            FieldType::Text      => 'textTabs',
            FieldType::Date      => 'dateSignedTabs',
            FieldType::Checkbox  => 'checkboxTabs',
        };
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function emptyTabBuckets(): array
    {
        return [
            'signHereTabs'    => [],
            'initialHereTabs' => [],
            'textTabs'        => [],
            'dateSignedTabs'  => [],
            'checkboxTabs'    => [],
        ];
    }

    /**
     * @param array<string, scalar|null> $metadata
     * @return list<array<string, string>>
     */
    private function buildCustomFields(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $name => $value) {
            $out[] = [
                'name'     => (string) $name,
                'value'    => $value === null ? '' : (string) $value,
                'required' => 'false',
                'show'     => 'false',
            ];
        }
        return $out;
    }

    /**
     * @param PreparedField[] $fields
     */
    private function assertFieldsResolvable(Envelope $envelope, Document $document, array $fields): void
    {
        foreach ($fields as $field) {
            if (!$envelope->signerByKey($field->signerKey) instanceof Signer) {
                throw new ProviderException(sprintf(
                    "Document '%s' references unknown signer key '%s' in field '%s'.",
                    $document->id,
                    $field->signerKey,
                    $field->fieldName,
                ));
            }
        }
    }
}
