# Changelog

All notable changes to `la-souris/document-signer-docusign` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-07-22

Initial public release.

### Added

- DocuSign eSignature implementation of the `la-souris/document-signer-sdk`
  `SignatureProvider` contract (`DocuSignProvider`, `DocuSignConfig`,
  `DocuSignClient`), with JWT authentication (`DocuSignJwtAuth`).
- DocuSign anchor-based placeholder replacement (`DocuSignPlaceholderReplacer`).
  Each tab carries a vertical `anchorYOffset` that drops its top edge onto the
  anchor line, matching ValidSign's placement, and every field type is sent with
  an explicit size.
- `DocuSignConfig::$anchorYOffsetPixels` — a document-wide vertical fine-tune (in
  pixels) added to every tab's offset (positive = down, default 0) to correct any
  residual uniform drift without a code change.
- `downloadSignedDocument()` takes the caller's `Document::$id`. The id →
  positional-id map is stored in an envelope-level `sdkDocumentMap` custom field
  (which DocuSign persists), with a normalized document-name match as a fallback
  and the `Summary.pdf` certificate always skipped. Not-yet-finalized documents
  raise the SDK's retryable `SignedDocumentUnavailableException`.
- `downloadAudit()` returns DocuSign's Certificate of Completion as a `.pdf`; the
  raw `audit_events` JSON feed remains available via
  `DocuSignClient::downloadAuditEventsJson()`.
- Webhook `EventType` mapping for DocuSign Connect callbacks.
- Requires `la-souris/document-signer-sdk` ^1.0 and PHP 8.3+.
