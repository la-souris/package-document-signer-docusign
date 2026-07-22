<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign\Tests\Webhook;

use LaSouris\DocumentSigner\DocuSign\Webhook\EventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class EventTypeTest extends TestCase
{
    #[Test]
    public function it_covers_the_documented_docusign_events(): void
    {
        // Guards against a case being dropped or renamed by accident. The
        // string values MUST match DocuSign Connect's `event` vocabulary verbatim.
        $expected = [
            'envelope-created',
            'envelope-sent',
            'envelope-resent',
            'envelope-delivered',
            'envelope-completed',
            'envelope-declined',
            'envelope-voided',
            'envelope-corrected',
            'envelope-purge',
            'recipient-sent',
            'recipient-resent',
            'recipient-delivered',
            'recipient-completed',
            'recipient-declined',
            'recipient-authenticationfailed',
            'recipient-autoresponded',
            'recipient-finish-later',
            'recipient-delegate',
            'recipient-reassign',
        ];

        // Unknown is a synthetic sentinel, not part of DocuSign's vocabulary.
        $realCases = array_filter(EventType::cases(), static fn (EventType $c) => $c !== EventType::Unknown);
        $actual = array_map(static fn (EventType $c) => $c->value, $realCases);

        sort($expected);
        sort($actual);

        self::assertSame($expected, array_values($actual));
    }

    #[Test]
    #[DataProvider('rawStrings')]
    public function raw_strings_resolve_via_from(string $raw, EventType $expected): void
    {
        self::assertSame($expected, EventType::from($raw));
    }

    /**
     * @return iterable<string, array{string, EventType}>
     */
    public static function rawStrings(): iterable
    {
        yield 'completed'  => ['envelope-completed', EventType::EnvelopeCompleted];
        yield 'declined'   => ['envelope-declined',  EventType::EnvelopeDeclined];
        yield 'voided'     => ['envelope-voided',    EventType::EnvelopeVoided];
        yield 'auth_fail'  => ['recipient-authenticationfailed', EventType::RecipientAuthenticationFailed];
        yield 'recip_done' => ['recipient-completed', EventType::RecipientCompleted];
    }

    #[Test]
    #[TestWith(['event'])]
    #[TestWith(['status'])]
    public function try_from_payload_picks_up_the_recognised_key(string $key): void
    {
        $payload = [$key => 'envelope-completed', 'envelopeId' => 'env-1'];

        self::assertSame(EventType::EnvelopeCompleted, EventType::tryFromPayload($payload));
    }

    #[Test]
    public function try_from_payload_prefers_the_event_key_over_status(): void
    {
        $payload = ['event' => 'envelope-completed', 'status' => 'envelope-declined'];

        self::assertSame(EventType::EnvelopeCompleted, EventType::tryFromPayload($payload));
    }

    #[Test]
    public function try_from_payload_returns_the_unknown_case_for_unrecognised_values(): void
    {
        self::assertSame(EventType::Unknown, EventType::tryFromPayload(['event' => 'mystery-event']));
        self::assertSame(EventType::Unknown, EventType::tryFromPayload(['event' => '']));
        self::assertSame(EventType::Unknown, EventType::tryFromPayload([]));
        self::assertSame(EventType::Unknown, EventType::tryFromPayload(['event' => 42])); // non-string
    }

    #[Test]
    public function the_unknown_case_is_semantically_inert(): void
    {
        self::assertFalse(EventType::Unknown->isCompleted());
        self::assertFalse(EventType::Unknown->isDeclined());
        self::assertFalse(EventType::Unknown->isFailure());
        self::assertFalse(EventType::Unknown->isProgress());
    }

    #[Test]
    public function every_case_implements_the_sdk_webhook_event_interface(): void
    {
        foreach (EventType::cases() as $case) {
            self::assertInstanceOf(\LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent::class, $case);
            self::assertSame($case->value, $case->value());
        }
    }

    #[Test]
    #[TestWith([EventType::EnvelopeCompleted, true])]
    #[TestWith([EventType::RecipientCompleted, false])]
    #[TestWith([EventType::EnvelopeDeclined, false])]
    public function is_completed_only_fires_for_envelope_completed(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isCompleted());
    }

    #[Test]
    #[TestWith([EventType::EnvelopeDeclined, true])]
    #[TestWith([EventType::RecipientDeclined, true])]
    #[TestWith([EventType::EnvelopeCompleted, false])]
    #[TestWith([EventType::EnvelopeVoided, false])]
    public function is_declined_covers_envelope_and_recipient_decline(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isDeclined());
    }

    #[Test]
    #[TestWith([EventType::RecipientAuthenticationFailed, true])]
    #[TestWith([EventType::RecipientAutoResponded, true])]
    #[TestWith([EventType::EnvelopeCompleted, false])]
    #[TestWith([EventType::EnvelopeDeclined, false])]
    public function is_failure_covers_technical_failure_cases(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isFailure());
    }

    #[Test]
    #[TestWith([EventType::EnvelopeSent, true])]
    #[TestWith([EventType::EnvelopeDelivered, true])]
    #[TestWith([EventType::RecipientCompleted, true])]
    #[TestWith([EventType::EnvelopeCompleted, false])]
    #[TestWith([EventType::EnvelopeCreated, false])]
    public function is_progress_covers_mid_flow_events(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isProgress());
    }

    #[Test]
    public function the_four_categorisers_are_non_overlapping(): void
    {
        foreach (EventType::cases() as $case) {
            $hits = (int) $case->isCompleted()
                  + (int) $case->isDeclined()
                  + (int) $case->isFailure()
                  + (int) $case->isProgress();
            self::assertLessThanOrEqual(1, $hits, "Multiple predicates fire for {$case->value}");
        }
    }
}
