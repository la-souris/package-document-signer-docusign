# DocuSign eSignature implementation of the document signer SDK

DocuSign eSignature implementation of the
[`SignatureProvider`](https://github.com/la-souris/package-document-signer-sdk/blob/main/src/Provider/SignatureProvider.php) contract from
[`la-souris/document-signer-sdk`](https://github.com/la-souris/package-document-signer-sdk).

Uses the OAuth 2.0 JWT user-consent grant to authenticate — no per-call user
interaction once the integration user has granted consent.

## Install

```bash
composer require la-souris/document-signer-docusign
```

## Quick start

```php
use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use LaSouris\DocumentSigner\DocuSign\DocuSignConfig;
use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;

$provider = new DocuSignProvider(new DocuSignConfig(
    integrationKey: getenv('DOCUSIGN_INTEGRATION_KEY'),
    userId:         getenv('DOCUSIGN_USER_ID'),
    accountId:      getenv('DOCUSIGN_ACCOUNT_ID'),
    privateKey:     file_get_contents('/path/to/private.pem'),
    oauthBaseUrl:   'account-d.docusign.com',           // 'account.docusign.com' in prod
    apiBaseUrl:     'https://demo.docusign.net/restapi', // production URL from userinfo
));

$receipt = $provider->send(new Envelope(
    name:         'Statement of Work',
    documents:    [new Document(
        id:   'sow',
        name: 'SoW',
        html: '<p>{[signature:customer:sig]} on {[date:customer:signdate]}</p>',
    )],
    signers:      [new Signer(key: 'customer', name: 'Jane Doe', email: 'jane@example.com')],
    emailSubject: 'Please sign the SoW',
));

echo $receipt->provider;           // "docusign" (DocuSignProvider::NAME)
echo $receipt->providerEnvelopeId; // DocuSign envelopeId GUID
```

## What it does

For every document in the envelope, this package:

1. Parses `{[type:signer:name]}` placeholders out of the HTML.
2. Substitutes each one with a hidden anchor token (`**DS:type:signer:name**`).
3. Renders the HTML to PDF via the SDK's `PdfRenderer`.
4. Base64-encodes each PDF and POSTs the envelope to
   `POST /v2.1/accounts/{accountId}/envelopes` with one anchor tab per
   placeholder under the correct recipient.
5. Returns an `EnvelopeReceipt` containing the DocuSign envelopeId and a
   normalised `EnvelopeStatus`.

Access tokens are minted via JWT and cached in memory by `DocuSignJwtAuth`
until 60s before expiry. Reuse one `DocuSignProvider` per process.

## Downloads

`downloadSigned()`, `downloadSignedDocument()`, and `downloadAudit()` all
write to a temp file and hand you an `\SplFileInfo` — check the extension:

```php
$archive = $provider->downloadSigned($envelopeId);
// $archive->getExtension() === 'zip'
// A ZIP with one signed PDF per envelope document (endpoint: /envelopes/{id}/documents/archive)

$pdf = $provider->downloadSignedDocument($envelopeId, 'sow');
// $pdf->getExtension() === 'pdf'
// The signed PDF for a single document. Pass the same id you set on
// Document::$id when calling send() ('sow' above) — resolved to DocuSign's
// positional id via the sdkDocumentMap envelope custom field (see setup below).
// Throws the retryable SignedDocumentUnavailableException if it isn't ready yet.

$audit = $provider->downloadAudit($envelopeId);
// $audit->getExtension() === 'pdf'
// The Certificate of Completion PDF — DocuSign's human-readable evidence report
// (endpoint: /envelopes/{id}/documents/certificate). For the raw audit-events
// JSON, call DocuSignClient::downloadAuditEventsJson() directly.
```

Callers own the file lifecycle — copy or `@unlink()` when done.

## Field mapping

| SDK `FieldType` | DocuSign tab bucket |
| --- | --- |
| `Signature` | `signHereTabs` |
| `Initials`  | `initialHereTabs` |
| `Text`      | `textTabs` |
| `Date`      | `dateSignedTabs` |
| `Checkbox`  | `checkboxTabs` |

## Field positioning

Fields are placed by anchoring each DocuSign tab to the hidden
`{[type:signer:name]}` marker in the rendered PDF. DocuSign otherwise seats an
anchored tab *above* the marker, so this package offsets every tab so its **top
edge lands on the marker** — the same reference point ValidSign uses, so the two
providers position identically. Each field type is also sent with an explicit
size mirroring ValidSign's.

If a whole document still sits slightly high or low on your account, nudge every
field with one knob — no code change:

```php
new DocuSignConfig(
    // ...credentials...
    anchorYOffsetPixels: 6,   // move every field 6px DOWN (negative = up). Default 0.
);
```

(In the Laravel/Symfony packages this is the `anchor_y_offset_pixels` provider
config value / `DOCUSIGN_ANCHOR_Y_OFFSET_PIXELS` env var.)

## One-time setup: user consent

The first time the integration key impersonates a user, the user must approve
consent in a browser:

```
https://account-d.docusign.com/oauth/auth
    ?response_type=code
    &scope=signature%20impersonation
    &client_id=YOUR_INTEGRATION_KEY
    &redirect_uri=https://www.docusign.com
```

Use `account.docusign.com` in production. After consent, JWT exchange runs
non-interactively from then on.

## One-time setup: envelope custom fields

`downloadSignedDocument($envelopeId, $documentId)` lets you fetch a single
signed document by the **same `Document::$id` you set when sending** — even
though DocuSign identifies documents only by a positional id (`"1"`, `"2"`) and
by name. To bridge that, `send()` records a small JSON map of
`Document::$id → positional id` in an **envelope-level text custom field named
`sdkDocumentMap`**, and the download reads it back from
`GET /envelopes/{id}/custom_fields`.

This field is written ad-hoc at send time and needs **no admin setup on a
default account** — it is an *envelope* custom field, not a DocuSign *document*
custom field (those must be pre-defined by an admin) and not the inline
per-document field DocuSign silently drops.

The one caveat: some accounts enable a restriction that only allows
**account-defined** custom fields on envelopes. If yours does, a DocuSign admin
must either relax that restriction or add an account custom field named
`sdkDocumentMap` (free text, not required, not shown) — otherwise the field is
dropped and `downloadSignedDocument()` can't resolve ids. You can confirm the
round-trip on your account with `verify-custom-field-roundtrip.php` in this
package.

## Requirements

- PHP 8.3
- `la-souris/document-signer-sdk`
- `firebase/php-jwt` (pulled automatically)
- A DocuSign developer/production account, integration key, RSA key pair, and
  the impersonated user's GUID
- Node.js + Puppeteer (for the default Browsershot renderer)

## Documentation

The full provider guide — credentials, JWT setup, demo vs prod URLs, endpoint
mapping, status mapping, sequential signing, token caching, troubleshooting —
lives in the SDK's docs:

- [DocuSign provider guide](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/providers/docusign.md)
- [Placeholder syntax](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/placeholders.md)
- [PDF rendering](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/pdf-rendering.md)
- [Architecture overview](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/architecture.md)
