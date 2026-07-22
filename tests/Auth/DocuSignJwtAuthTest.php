<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign\Tests\Auth;

use LaSouris\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LaSouris\DocumentSigner\DocuSign\DocuSignConfig;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignJwtAuthTest extends TestCase
{
    private static ?string $privateKey = null;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            self::markTestSkipped('openssl_pkey_new failed; skipping JWT tests.');
        }
        openssl_pkey_export($resource, $pem);
        self::$privateKey = $pem;
    }

    #[Test]
    public function it_exchanges_jwt_assertion_for_access_token(): void
    {
        $history = new \ArrayObject();
        $auth = $this->buildAuth([
            new Response(200, [], json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])),
        ], $history);

        self::assertSame('tok-1', $auth->accessToken());

        self::assertCount(1, $history);
        $body = (string) $history[0]['request']->getBody();
        self::assertStringContainsString('grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer', $body);
        self::assertStringContainsString('assertion=', $body);
    }

    #[Test]
    public function repeated_calls_reuse_the_cached_token(): void
    {
        $history = new \ArrayObject();
        $auth = $this->buildAuth([
            new Response(200, [], json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])),
        ], $history);

        $auth->accessToken();
        $auth->accessToken();
        $auth->accessToken();

        self::assertCount(1, $history, 'token endpoint should be hit only once while cache is warm');
    }

    #[Test]
    public function it_throws_on_malformed_response(): void
    {
        $history = new \ArrayObject();
        $auth = $this->buildAuth([
            new Response(200, [], json_encode(['oops' => true])),
        ], $history);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('missing access_token or expires_in');

        $auth->accessToken();
    }

    /**
     * @param array<int, Response>                    $responses
     * @param \ArrayObject<int, array<string, mixed>> $history
     */
    private function buildAuth(array $responses, \ArrayObject $history): DocuSignJwtAuth
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        $config = new DocuSignConfig(
            integrationKey: 'iKey',
            userId:         'uId',
            accountId:      'aId',
            privateKey:     (string) self::$privateKey,
        );

        return new DocuSignJwtAuth($config, $http);
    }
}
