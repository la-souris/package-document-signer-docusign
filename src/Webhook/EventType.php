<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign\Webhook;

use LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent;

/**
 * Every callback event DocuSign Connect can emit for an envelope.
 *
 * String values match the `event` token in Connect's JSON payload verbatim
 * (`envelope-completed`, `recipient-declined`, …) so a raw value translates via
 * `EventType::from()`. Implements {@see WebhookEvent} so application code can
 * dispatch on the semantic category (`isCompleted()`, `isDeclined()`, …)
 * without knowing the provider-native token.
 *
 * Consumers typically receive one of these on the Laravel package's
 * {@see \LaSouris\DocumentSigner\Laravel\Events\DocumentSignerWebhookReceived}
 * event and use {@see tryFromPayload()} to resolve the case from the decoded
 * JSON body.
 */
enum EventType: string implements WebhookEvent
{
    /** Envelope was created as a draft. */
    case EnvelopeCreated  = 'envelope-created';

    /** Envelope was sent to its first recipient. */
    case EnvelopeSent     = 'envelope-sent';

    /** Envelope was re-sent to a recipient. */
    case EnvelopeResent   = 'envelope-resent';

    /** A recipient opened the envelope. */
    case EnvelopeDelivered = 'envelope-delivered';

    /** Every recipient finished; the envelope is complete. */
    case EnvelopeCompleted = 'envelope-completed';

    /** A recipient declined to sign; the envelope is stopped. */
    case EnvelopeDeclined  = 'envelope-declined';

    /** The envelope was voided by the sender. */
    case EnvelopeVoided    = 'envelope-voided';

    /** The envelope was corrected (recipients/fields changed) after sending. */
    case EnvelopeCorrected = 'envelope-corrected';

    /** The envelope's documents were purged from DocuSign. */
    case EnvelopePurge     = 'envelope-purge';

    /** An envelope was sent to an individual recipient. */
    case RecipientSent     = 'recipient-sent';

    /** An envelope was re-sent to an individual recipient. */
    case RecipientResent   = 'recipient-resent';

    /** An individual recipient opened the envelope. */
    case RecipientDelivered = 'recipient-delivered';

    /** An individual recipient finished signing. */
    case RecipientCompleted = 'recipient-completed';

    /** An individual recipient declined to sign. */
    case RecipientDeclined  = 'recipient-declined';

    /** A recipient failed identity/access-code authentication. */
    case RecipientAuthenticationFailed = 'recipient-authenticationfailed';

    /** A recipient's email auto-responded (out-of-office, bounce). */
    case RecipientAutoResponded = 'recipient-autoresponded';

    /** A recipient chose to finish signing later. */
    case RecipientFinishLater = 'recipient-finish-later';

    /** A recipient delegated signing to someone else. */
    case RecipientDelegate  = 'recipient-delegate';

    /** A recipient was reassigned to another signer. */
    case RecipientReassign  = 'recipient-reassign';

    /**
     * A verified callback whose token DocuSign sent but this enum doesn't
     * model. Synthetic — DocuSign never emits this value; {@see tryFromPayload()}
     * resolves to it so callers always get a non-null event. All four `is…()`
     * predicates are `false`; the raw body remains on the dispatched event.
     */
    case Unknown = '__UNKNOWN__';

    public function value(): string
    {
        return $this->value;
    }

    public function isCompleted(): bool
    {
        return $this === self::EnvelopeCompleted;
    }

    public function isDeclined(): bool
    {
        return match ($this) {
            self::EnvelopeDeclined, self::RecipientDeclined => true,
            default                                         => false,
        };
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::RecipientAuthenticationFailed,
            self::RecipientAutoResponded => true,
            default                      => false,
        };
    }

    public function isProgress(): bool
    {
        return match ($this) {
            self::EnvelopeSent,
            self::EnvelopeDelivered,
            self::RecipientSent,
            self::RecipientDelivered,
            self::RecipientCompleted => true,
            default                  => false,
        };
    }

    /**
     * Best-effort resolution from a decoded Connect callback body. DocuSign
     * keys the event under `event` in the JSON payload; `status` is accepted as
     * a fallback for tenants/mappings that surface it there.
     *
     * Always returns a case: a token that matches no known event resolves to
     * {@see self::Unknown}, so callers never have to null-check the result.
     *
     * @param array<string, mixed> $payload
     */
    public static function tryFromPayload(array $payload): self
    {
        foreach (['event', 'status'] as $key) {
            $raw = $payload[$key] ?? null;
            if (is_string($raw)) {
                $case = self::tryFrom($raw);
                if ($case !== null) {
                    return $case;
                }
            }
        }
        return self::Unknown;
    }
}
