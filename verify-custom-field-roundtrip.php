<?php

declare(strict_types=1);

/**
 * Verifies the one assumption behind the v2.3.1 downloadSignedDocument() fix:
 * that an ad-hoc ENVELOPE-level text custom field (`sdkDocumentMap`) supplied at
 * create time survives a round-trip on YOUR DocuSign account — i.e. it is
 * returned by GET /envelopes/{id}/custom_fields and not silently dropped the way
 * inline per-document fields are.
 *
 * It creates a DRAFT envelope (status "created", nothing is sent to any signer),
 * reads the custom fields back, and reports PASS/FAIL. Delete the draft from the
 * DocuSign web console afterwards if you like.
 *
 * Usage (from the monorepo root or the docu-sign package):
 *   DOCUSIGN_INTEGRATION_KEY=... \
 *   DOCUSIGN_USER_ID=... \
 *   DOCUSIGN_ACCOUNT_ID=... \
 *   DOCUSIGN_PRIVATE_KEY_PATH=/path/to/private.pem \
 *   DOCUSIGN_OAUTH_BASE_URL=account-d.docusign.com \
 *   DOCUSIGN_API_BASE_URL=https://demo.docusign.net/restapi \
 *   php docu-sign/verify-custom-field-roundtrip.php /path/to/any-sample.pdf
 *
 * (Use your PRODUCTION oauth/api base URLs + account if that's where the
 *  restriction you're worried about lives — the setting is per-account.)
 */

use LaSouris\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LaSouris\DocumentSigner\DocuSign\DocuSignConfig;
use LaSouris\DocumentSigner\DocuSign\Http\DocuSignClient;

$autoload = is_file(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : __DIR__ . '/../vendor/autoload.php';
require $autoload;

$pdfPath = $argv[1] ?? null;
if ($pdfPath === null || !is_readable($pdfPath)) {
    fwrite(STDERR, "Pass a path to a sample PDF as the first argument.\n");
    exit(2);
}

$config = new DocuSignConfig(
    integrationKey: (string) getenv('DOCUSIGN_INTEGRATION_KEY'),
    userId:         (string) getenv('DOCUSIGN_USER_ID'),
    accountId:      (string) getenv('DOCUSIGN_ACCOUNT_ID'),
    privateKey:     (string) file_get_contents((string) getenv('DOCUSIGN_PRIVATE_KEY_PATH')),
    oauthBaseUrl:   (string) (getenv('DOCUSIGN_OAUTH_BASE_URL') ?: 'account-d.docusign.com'),
    apiBaseUrl:     (string) (getenv('DOCUSIGN_API_BASE_URL') ?: 'https://demo.docusign.net/restapi'),
);

$client = new DocuSignClient($config, new DocuSignJwtAuth($config));

$expected = json_encode(['O-2607-JZXK-offer' => '1', 'C-2607-WPNB-sepa' => '2'], JSON_THROW_ON_ERROR);

echo "Creating draft envelope with sdkDocumentMap custom field...\n";
$created = $client->createEnvelope([
    'emailSubject' => 'SDK custom-field round-trip check (draft — safe to delete)',
    'status'       => 'created', // DRAFT: nothing is sent to anyone
    'documents'    => [[
        'documentBase64' => base64_encode((string) file_get_contents($pdfPath)),
        'name'           => 'Roundtrip Check',
        'fileExtension'  => 'pdf',
        'documentId'     => '1',
    ]],
    'customFields' => [
        'textCustomFields' => [
            ['name' => 'sdkDocumentMap', 'value' => $expected, 'required' => 'false', 'show' => 'false'],
        ],
    ],
]);

$envelopeId = $created['envelopeId'] ?? null;
if (!is_string($envelopeId) || $envelopeId === '') {
    fwrite(STDERR, "FAIL: no envelopeId returned. Response:\n" . json_encode($created, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
echo "  envelopeId: {$envelopeId}\n";

echo "Reading GET /envelopes/{$envelopeId}/custom_fields ...\n";
$fields = $client->getEnvelopeCustomFields($envelopeId);

$found = null;
foreach (($fields['textCustomFields'] ?? []) as $f) {
    if (($f['name'] ?? null) === 'sdkDocumentMap') {
        $found = $f['value'] ?? null;
    }
}

echo "\n--- RESULT ---\n";
if ($found === $expected) {
    echo "PASS ✅  sdkDocumentMap round-tripped intact — the v2.3.1 fix works on this account.\n";
    echo "        (Draft envelope {$envelopeId} left in your account — delete it if you wish.)\n";
    exit(0);
}

echo "FAIL ❌  sdkDocumentMap did not come back as sent.\n";
echo "         expected: {$expected}\n";
echo "         got:      " . var_export($found, true) . "\n";
echo "         Your account likely restricts ad-hoc envelope custom fields.\n";
echo "         Full custom_fields response:\n" . json_encode($fields, JSON_PRETTY_PRINT) . "\n";
exit(1);
