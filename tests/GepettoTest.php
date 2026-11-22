<?php

declare(strict_types=1);

namespace Gepetto\Tests;

use Gepetto\Gepetto;
use Gepetto\GepettoException;
use Gepetto\Job;
use Gepetto\TransportException;
use Gepetto\Webhook;
use PHPUnit\Framework\TestCase;

/** The client with a fake transport: each test says what the API answers, and checks what was sent. */
final class GepettoTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    private array $requests = [];

    /**
     * A client whose transport answers the given responses in order.
     *
     * @param list<array{status: int, body: array<mixed>|string}|TransportException> $answers
     */
    private function client(array $answers): Gepetto
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$answers): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            $answer = array_shift($answers);
            if ($answer instanceof TransportException) {
                throw $answer;
            }
            $text = is_string($answer['body']) ? $answer['body'] : json_encode($answer['body']);
            return ['status' => $answer['status'], 'body' => $text];
        };
        return new Gepetto('test_key', ['transport' => $transport, 'timeout' => 30]);
    }

    private static function job(string $status, ?string $output = null): array
    {
        return [
            'id' => 'job_1', 'status' => $status, 'tool' => 'furnish', 'mode' => 'auto', 'output' => $output,
            'error' => null, 'priceCents' => 0, 'test' => true, 'createdAt' => '2026-11-22T10:00:00Z', 'finishedAt' => null,
        ];
    }

    private function header(int $request, string $name): ?string
    {
        foreach ($this->requests[$request]['headers'] as $line) {
            if (str_starts_with(strtolower($line), strtolower($name) . ':')) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }

    public function testATool_SendsItsParametersAndReturnsTheFinishedJob(): void
    {
        $gepetto = $this->client([['status' => 200, 'body' => self::job('success', 'https://img/output.jpg')]]);

        $job = $gepetto->furnish(['url' => 'https://img/photo.jpg', 'roomId' => 'living_room', 'styleId' => 'scandinave']);

        $this->assertSame('https://img/output.jpg', $job->output);
        $this->assertTrue($job->isSuccess());
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertStringEndsWith('/v2/furnish', $this->requests[0]['url']);
        $this->assertSame(
            ['url' => 'https://img/photo.jpg', 'roomId' => 'living_room', 'styleId' => 'scandinave'],
            json_decode($this->requests[0]['body'], true),
        );
        $this->assertSame('Bearer test_key', $this->header(0, 'Authorization'));
        $this->assertNotEmpty($this->header(0, 'Idempotency-Key'));
    }

    public function testNullParametersAreNotSent(): void
    {
        $gepetto = $this->client([['status' => 200, 'body' => self::job('success', 'https://img/o.jpg')]]);

        $gepetto->enhance(['url' => 'https://img/p.jpg', 'straighten' => null]);

        $this->assertSame(['url' => 'https://img/p.jpg'], json_decode($this->requests[0]['body'], true));
    }

    public function testARunningJob_IsFollowedUntilItsImageIsReady(): void
    {
        $gepetto = $this->client([
            ['status' => 200, 'body' => self::job('processing')],
            ['status' => 200, 'body' => self::job('success', 'https://img/o.jpg')],
        ]);

        $job = $gepetto->declutter(['url' => 'https://img/p.jpg']);

        $this->assertSame('https://img/o.jpg', $job->output);
        $this->assertSame('GET', $this->requests[1]['method']);
        $this->assertStringEndsWith('/v2/job/job_1', $this->requests[1]['url']);
    }

    public function testAWebhook_ReturnsThePendingJobAtOnce(): void
    {
        $gepetto = $this->client([['status' => 202, 'body' => self::job('pending')]]);

        $job = $gepetto->sunshine(['url' => 'https://img/p.jpg', 'skyId' => 'sunny', 'webhook' => 'https://me/hook']);

        $this->assertSame('pending', $job->status);
        $this->assertCount(1, $this->requests);
    }

    public function testA503_IsRetriedWithTheSameIdempotencyKey(): void
    {
        $gepetto = $this->client([
            ['status' => 503, 'body' => ['status' => 'error', 'code' => 'internal_error', 'message' => 'Try again.']],
            ['status' => 200, 'body' => self::job('success', 'https://img/o.jpg')],
        ]);

        $gepetto->upscale(['url' => 'https://img/p.jpg'], ['idempotencyKey' => 'photo-42:upscale']);

        $this->assertCount(2, $this->requests);
        $this->assertSame('photo-42:upscale', $this->header(0, 'Idempotency-Key'));
        $this->assertSame('photo-42:upscale', $this->header(1, 'Idempotency-Key'));
    }

    public function testADroppedConnection_IsRetriedWithTheSameKey(): void
    {
        $gepetto = $this->client([
            new TransportException('Connection reset by peer'),
            ['status' => 200, 'body' => self::job('success', 'https://img/o.jpg')],
        ]);

        $job = $gepetto->enhance(['url' => 'https://img/p.jpg']);

        $this->assertSame('https://img/o.jpg', $job->output);
        $this->assertSame($this->header(0, 'Idempotency-Key'), $this->header(1, 'Idempotency-Key'));
    }

    public function testAnApiError_BecomesAGepettoExceptionWithItsCodeAndParam(): void
    {
        $gepetto = $this->client([[
            'status' => 400,
            'body' => ['status' => 'error', 'code' => 'unknown_style', 'message' => 'No style "modrn".', 'param' => 'styleId'],
        ]]);

        try {
            $gepetto->furnish(['url' => 'https://img/p.jpg', 'roomId' => 'bedroom', 'styleId' => 'modrn']);
            $this->fail('An exception was expected');
        } catch (GepettoException $e) {
            $this->assertSame('unknown_style', $e->errorCode);
            $this->assertSame('styleId', $e->param);
            $this->assertSame(400, $e->status);
            $this->assertSame(400, $e->getCode());
            $this->assertSame('No style "modrn".', $e->getMessage());
        }
        $this->assertCount(1, $this->requests, 'A 400 is not retried');
    }

    public function testAFailedRender_ThrowsWithTheJob(): void
    {
        $failed = self::job('error');
        $failed['error'] = ['code' => 'render_failed', 'message' => 'The render failed. You were not charged.'];
        $gepetto = $this->client([['status' => 200, 'body' => $failed]]);

        try {
            $gepetto->furnish(['url' => 'https://img/p.jpg', 'roomId' => 'bedroom', 'styleId' => 'modern']);
            $this->fail('An exception was expected');
        } catch (GepettoException $e) {
            $this->assertSame('render_failed', $e->errorCode);
            $this->assertSame('job_1', $e->jobId);
            $this->assertInstanceOf(Job::class, $e->job);
        }
    }

    public function testWithoutAKey_TheMessageSaysHowToGetOne(): void
    {
        $saved = [getenv('GEPETTO_API_KEY'), $_ENV['GEPETTO_API_KEY'] ?? null, $_SERVER['GEPETTO_API_KEY'] ?? null];
        putenv('GEPETTO_API_KEY');
        unset($_ENV['GEPETTO_API_KEY'], $_SERVER['GEPETTO_API_KEY']);
        try {
            new Gepetto();
            $this->fail('An exception was expected');
        } catch (GepettoException $e) {
            $this->assertSame('missing_api_key', $e->errorCode);
            $this->assertStringContainsString('npx @gepetto/cli', $e->getMessage());
        } finally {
            if ($saved[0] !== false) {
                putenv('GEPETTO_API_KEY=' . $saved[0]);
            }
        }
    }

    public function testTheKeyIsReadFromTheEnvironment(): void
    {
        $_ENV['GEPETTO_API_KEY'] = 'test_from_env';
        try {
            $this->assertTrue((new Gepetto())->isTest());
        } finally {
            unset($_ENV['GEPETTO_API_KEY']);
        }
    }

    public function testListJobs_ReturnsJobs(): void
    {
        $gepetto = $this->client([['status' => 200, 'body' => [self::job('success', 'https://img/o.jpg')]]]);

        $jobs = $gepetto->listJobs(['status' => 'success', 'limit' => 5]);

        $this->assertInstanceOf(Job::class, $jobs[0]);
        $this->assertStringEndsWith('/v2/jobs?status=success&limit=5', $this->requests[0]['url']);
    }

    public function testMaskFromFile_GivesADataUrl(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mask');
        file_put_contents($file, "\x89PNG\r\n\x1a\nrest");
        try {
            $this->assertStringStartsWith('data:image/png;base64,', Gepetto::maskFromFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testAWebhook_IsVerified(): void
    {
        $body = json_encode(self::job('success', 'https://img/o.jpg'));
        $time = (string) time();
        $signature = "t={$time},v1=" . hash_hmac('sha256', "{$time}.{$body}", 'whsec_test');

        $job = Webhook::verify($body, $signature, 'whsec_test');

        $this->assertSame('https://img/o.jpg', $job->output);
    }

    public function testAWebhookWithAWrongSignature_IsRefused(): void
    {
        $body = json_encode(self::job('success', 'https://img/o.jpg'));
        $time = (string) time();
        $signature = "t={$time},v1=" . hash_hmac('sha256', "{$time}.{$body}", 'another_secret');

        $this->expectException(GepettoException::class);
        $this->expectExceptionMessage('signature mismatch');
        Webhook::verify($body, $signature, 'whsec_test');
    }

    public function testAnOldWebhook_IsRefused(): void
    {
        $body = '{}';
        $time = (string) (time() - 3600);
        $signature = "t={$time},v1=" . hash_hmac('sha256', "{$time}.{$body}", 'whsec_test');

        $this->expectExceptionMessage('timestamp too old');
        Webhook::verify($body, $signature, 'whsec_test');
    }
}
