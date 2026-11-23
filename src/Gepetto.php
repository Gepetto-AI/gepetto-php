<?php

declare(strict_types=1);

namespace Gepetto;

/**
 * The client of the Gepetto API v2: real estate photo editing.
 *
 *     $gepetto = new Gepetto(); // reads GEPETTO_API_KEY
 *
 *     $job = $gepetto->furnish([
 *         'url' => 'https://example.com/empty-living-room.jpg',
 *         'roomId' => 'living_room',
 *         'styleId' => 'scandinave',
 *     ]);
 *
 *     echo $job->output; // the URL of the image
 *
 * Each tool waits for the image and returns the finished Job, or throws a GepettoException.
 * With 'webhook' in the parameters, it returns at once with the pending job instead.
 */
final class Gepetto
{
    public const VERSION = '0.1.2';
    public const DEFAULT_BASE_URL = 'https://europe-west1-gepettoai.cloudfunctions.net/v2';

    /** Seconds. A render is stopped by the API after 450 s: 10 minutes covers it, retries included. */
    private const DEFAULT_TIMEOUT = 600.0;
    private const DEFAULT_MAX_RETRIES = 2;
    /** Seconds between two checks of a running job. */
    private const POLL_INTERVAL = 2.5;
    /** Seconds a video waits by default: it takes 2 to 10 minutes, and the API gives it up after 30. */
    private const VIDEO_TIMEOUT = 1800.0;

    public readonly string $baseUrl;
    /** Seconds: how long a call waits for its image (each call can set its own). */
    public readonly float $timeout;
    /** Retries after a network error, a 429 or a 5xx. */
    public readonly int $maxRetries;
    private readonly string $apiKey;
    /** @var callable(string, string, list<string>, ?string, float): array{status: int, body: string} */
    private $transport;

    /**
     * @param string|array{apiKey?: string, baseUrl?: string, timeout?: float, maxRetries?: int, transport?: callable}|null $apiKeyOrOptions
     *        The API key, or the options. Without a key, GEPETTO_API_KEY is read.
     * @param array{baseUrl?: string, timeout?: float, maxRetries?: int, transport?: callable} $options
     */
    public function __construct(string|array|null $apiKeyOrOptions = null, array $options = [])
    {
        if (is_string($apiKeyOrOptions)) {
            $options['apiKey'] = $apiKeyOrOptions;
        } elseif (is_array($apiKeyOrOptions)) {
            $options = $apiKeyOrOptions + $options;
        }

        $apiKey = trim((string) ($options['apiKey'] ?? self::env('GEPETTO_API_KEY') ?? ''));
        if ($apiKey === '') {
            throw new GepettoException(
                'missing_api_key',
                'No API key: pass it to new Gepetto(...) or set GEPETTO_API_KEY. '
                . 'Get a free test key with `npx @gepetto/cli login` then `npx @gepetto/cli init`.',
            );
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim((string) ($options['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');
        $this->timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->maxRetries = max(0, (int) ($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES));
        $this->transport = $options['transport'] ?? new CurlTransport();
    }

    /** A test key (`test_...`): free renders, 200 per account. */
    public function isTest(): bool
    {
        return str_starts_with($this->apiKey, 'test_');
    }

    // --- Tools ---------------------------------------------------------------------------------------------------
    // Every tool takes the parameters of its endpoint (same names as the docs) and, optionally:
    //   ['idempotencyKey' => 'photo-42:furnish', 'timeout' => 120]
    // The url of the photo is a public http(s) URL: upload the photo to your own storage first.

    /**
     * Without a mask, the whole room is emptied; with a mask, only what is painted in white is removed.
     *
     * @param array{url: string, mask?: string, keepKitchen?: bool, keepBathroom?: bool, keepFireplace?: bool, webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function declutter(array $params, array $options = []): Job
    {
        return $this->render('/declutter', $params, $options);
    }

    /**
     * Without a mask, the whole room is furnished (roomId and styleId). With a mask, the painted zone is furnished as
     * a room (roomId and styleId), or with one piece of furniture (furnitureId, styleId optional).
     *
     * @param array{url: string, roomId?: string, styleId?: string, mask?: string, furnitureId?: string, webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function furnish(array $params, array $options = []): Job
    {
        return $this->render('/furnish', $params, $options);
    }

    /**
     * A blue sky and the matching light, outside or through the windows.
     *
     * @param array{url: string, skyId: string, lightProjection?: bool, poolLight?: bool, addLeafs?: bool, webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function sunshine(array $params, array $options = []): Job
    {
        return $this->render('/sunshine', $params, $options);
    }

    /**
     * The room redesigned in a style: furniture, materials and colours change, the architecture stays.
     *
     * @param array{url: string, roomId: string, styleId: string, preserveFloor?: bool, webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function creativeRedesign(array $params, array $options = []): Job
    {
        return $this->render('/creative-redesign', $params, $options);
    }

    /**
     * One surface per call: 'walls' (wallType, or 'custom' with customWallColor), 'floor' (floorType) or 'finish' (finishId).
     *
     * @param array{url: string, surface: 'walls'|'floor'|'finish', wallType?: string, customWallColor?: string, preserveCeiling?: bool, floorType?: string, includeBaseboard?: bool, finishId?: string, webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function surfaces(array $params, array $options = []): Job
    {
        return $this->render('/surfaces', $params, $options);
    }

    /**
     * Light and colours corrected, nothing redrawn, at the size of the photo. straighten: converging verticals.
     *
     * @param array{url: string, straighten?: bool, webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function enhance(array $params, array $options = []): Job
    {
        return $this->render('/enhance', $params, $options);
    }

    /**
     * Enlarged to about 4 megapixels ('2k') or 16 megapixels ('4k', the default).
     *
     * @param array{url: string, resolution?: '2k'|'4k', webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function upscale(array $params, array $options = []): Job
    {
        return $this->render('/upscale', $params, $options);
    }

    // --- Videos: 6 seconds, MP4, live keys only ------------------------------------------------------------------
    // A video takes 2 to 10 minutes: the method waits up to 30 minutes (or set 'timeout'), or returns at once with
    // 'webhook'.

    /**
     * A slow camera move in the photo. 0.80 € per video.
     *
     * @param array{url: string, camera: 'zoom_in'|'zoom_out'|'zoom_in_pan_left'|'zoom_in_pan_right'|'crane_up'|'crane_down'|'crab_left'|'crab_right', timelapse?: bool, resolution?: '768p'|'1080p', webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function cameraMove(array $params, array $options = []): Job
    {
        return $this->render('/video/camera-move', $params, $options + ['timeout' => self::VIDEO_TIMEOUT]);
    }

    /**
     * From a photo to its edited version. The images: 'jobId' (a finished render: its original goes to its result),
     * or 'url' and 'endUrl' of the same proportions. The animation: 'transitionId' (listTransitions()) or your own
     * 'prompt' in English. 1.60 € per video.
     *
     * @param array{jobId?: string, url?: string, endUrl?: string, transitionId?: string, prompt?: string, negativePrompt?: string, webhook?: string} $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    public function transition(array $params, array $options = []): Job
    {
        return $this->render('/video/transition', $params, $options + ['timeout' => self::VIDEO_TIMEOUT]);
    }

    // --- Jobs ----------------------------------------------------------------------------------------------------

    public function getJob(string $id): Job
    {
        return Job::fromArray($this->get('/job/' . rawurlencode($id)));
    }

    /**
     * Waits for a job started with a webhook, or after a timeout. Returns it once its image is ready.
     *
     * @param float|null $timeout Seconds (by default the client's).
     */
    public function waitForJob(string $id, ?float $timeout = null): Job
    {
        $deadline = microtime(true) + ($timeout ?? $this->timeout);
        return self::finished($this->poll($id, $deadline));
    }

    /**
     * The latest jobs of the key, newest first.
     *
     * @param array{status?: 'pending'|'processing'|'success'|'error', limit?: int} $filters
     * @return list<Job>
     */
    public function listJobs(array $filters = []): array
    {
        $query = http_build_query(array_filter($filters, static fn ($value) => $value !== null));
        $jobs = $this->get('/jobs' . ($query === '' ? '' : '?' . $query));
        return array_map(static fn (array $job) => Job::fromArray($job), array_values($jobs));
    }

    // --- Lists: the valid ids of the parameters ------------------------------------------------------------------

    /** @return list<array{id: string, name: string, name_en?: string, modes: list<string>}> roomId, with the modes of each room ('auto': whole room, 'zone': with a mask). */
    public function listRooms(): array
    {
        return $this->get('/rooms');
    }

    /** @return list<array{id: string, name: string}> styleId. */
    public function listStyles(): array
    {
        return $this->get('/styles');
    }

    /** @return array<string, list<array{id: string, name: string, name_en?: string, type: string}>> furnitureId, grouped by type. */
    public function listFurnitures(): array
    {
        return $this->get('/furnitures');
    }

    /** @return list<array{id: string, name: string, name_en?: string, preview: ?string}> transitionId: the animations of transition(). */
    public function listTransitions(): array
    {
        return $this->get('/video/transitions');
    }

    /** @return list<array{id: string, name: string, name_en?: string}> skyId. */
    public function listSkies(): array
    {
        return $this->get('/skies');
    }

    /** @return array{walls: list<array{id: string, name: string}>, floors: list<array{id: string, name: string}>, finishes: list<array{id: string, name: string}>} wallType, floorType, finishId. */
    public function listSurfaces(): array
    {
        return $this->get('/surfaces');
    }

    // --- Account -------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> The key type, the test renders used, the webhook secret... */
    public function account(): array
    {
        return $this->get('/account');
    }

    /** @return array<string, mixed> The consumption of the current billing period. */
    public function usage(): array
    {
        return $this->get('/usage');
    }

    // --- Helpers -------------------------------------------------------------------------------------------------

    /**
     * A local image as a base64 data URL, to send as a `mask` without hosting it (PNG, JPEG or WebP, 5 MB at most).
     * A mask is a black image with the zone painted in white, at the proportions of the photo.
     */
    public static function maskFromFile(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new GepettoException('invalid_parameter', "Could not read the mask file {$path}.");
        }
        $type = match (true) {
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8") => 'image/jpeg',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => throw new GepettoException('invalid_parameter', "The mask {$path} must be a PNG, JPEG or WebP image."),
        };
        return "data:{$type};base64," . base64_encode($bytes);
    }

    /** An environment variable, from getenv(), $_ENV or $_SERVER (frameworks fill one or the other). */
    public static function env(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    // --- Internals -----------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     * @param array{idempotencyKey?: string, timeout?: float} $options
     */
    private function render(string $path, array $params, array $options): Job
    {
        $params = array_filter($params, static fn ($value) => $value !== null);
        $deadline = microtime(true) + (float) ($options['timeout'] ?? $this->timeout);

        // The same key on every attempt: a retry answers the job already created, never a second render (nor a
        // second charge). It also covers a connection dropped while the render runs: the retry gets the running job,
        // which is then followed with GET /job/{id}.
        $idempotencyKey = (string) ($options['idempotencyKey'] ?? bin2hex(random_bytes(16)));

        $response = $this->send('POST', $path, $params, $idempotencyKey, $deadline, retryConnection: true);
        $job = Job::fromArray($this->json($response));

        if (isset($params['webhook'])) {
            return $job;
        }
        if (!$job->isFinished()) {
            $job = $this->poll($job->id, $deadline);
        }
        return self::finished($job);
    }

    /** @return array<mixed> */
    private function get(string $path): array
    {
        return $this->json($this->send('GET', $path, null, null, microtime(true) + $this->timeout));
    }

    private function poll(string $id, float $deadline): Job
    {
        while (true) {
            $job = Job::fromArray($this->json($this->send('GET', '/job/' . rawurlencode($id), null, null, $deadline)));
            if ($job->isFinished()) {
                return $job;
            }
            if (microtime(true) + self::POLL_INTERVAL >= $deadline) {
                throw new GepettoException(
                    'timeout',
                    "Job {$id} is still {$job->status}: stopped waiting (timeout). It goes on: "
                    . "follow it with getJob('{$id}') or waitForJob('{$id}').",
                    jobId: $id,
                    job: $job,
                );
            }
            usleep((int) (self::POLL_INTERVAL * 1_000_000));
        }
    }

    /** The job, if its image is ready; else its error. A failed render is never charged. */
    private static function finished(Job $job): Job
    {
        if ($job->isSuccess()) {
            return $job;
        }
        throw new GepettoException(
            $job->error['code'] ?? 'render_failed',
            $job->error['message'] ?? 'The render failed. You were not charged. Try again.',
            jobId: $job->id,
            job: $job,
        );
    }

    /**
     * The body of a successful answer; else the error the API sent.
     *
     * @param array{status: int, data: mixed} $response
     * @return array<mixed>
     */
    private function json(array $response): array
    {
        ['status' => $status, 'data' => $data] = $response;
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new GepettoException('invalid_response', 'The API answered something that is not JSON.', $status);
            }
            return $data;
        }

        $error = is_array($data) ? $data : [];
        throw new GepettoException(
            (string) ($error['code'] ?? ($status === 429 ? 'rate_limited' : 'invalid_response')),
            (string) ($error['message'] ?? "The API answered HTTP {$status}."),
            $status,
            isset($error['param']) ? (string) $error['param'] : null,
            // A render that failed during a synchronous call (502) carries its job id
            isset($error['id']) ? (string) $error['id'] : null,
        );
    }

    /**
     * Sends a request, with retries after a network error, a 429 or a 5xx (a POST only with an idempotency key).
     *
     * @param array<string, mixed>|null $body
     * @return array{status: int, data: mixed}
     */
    private function send(
        string $method,
        string $path,
        ?array $body,
        ?string $idempotencyKey,
        float $deadline,
        bool $retryConnection = false,
    ): array {
        $retryable = $method === 'GET' || $idempotencyKey !== null;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: gepetto-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        for ($attempt = 0; ; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw self::timeoutError();
            }

            try {
                $answer = ($this->transport)($method, $this->baseUrl . $path, $headers, $payload, $remaining);
            } catch (TransportException $e) {
                if ($e->timedOut) {
                    throw self::timeoutError();
                }
                $maxAttempts = $retryConnection ? max($this->maxRetries, 2) : $this->maxRetries;
                if ($retryable && $attempt < $maxAttempts && microtime(true) < $deadline) {
                    self::backoff($attempt);
                    continue;
                }
                $retryHint = $idempotencyKey === null
                    ? ''
                    : " Retry with the same idempotencyKey ({$idempotencyKey}): a render already started is not run twice.";
                throw new GepettoException(
                    'connection_error',
                    "Could not reach the Gepetto API ({$e->getMessage()}).{$retryHint}",
                    previous: $e,
                );
            }

            $status = $answer['status'];
            $data = json_decode($answer['body'], true);

            // 502 is a render that failed: it is answered, not retried
            $transient = $status === 429 || ($status >= 500 && $status !== 502);
            if ($transient && $retryable && $attempt < $this->maxRetries && microtime(true) < $deadline) {
                self::backoff($attempt);
                continue;
            }
            return ['status' => $status, 'data' => $data];
        }
    }

    /** Waits 1 s, 2 s, 4 s... (8 s at most), with a little jitter. */
    private static function backoff(int $attempt): void
    {
        $seconds = min(8, 2 ** $attempt) * (0.8 + mt_rand() / mt_getrandmax() * 0.4);
        usleep((int) ($seconds * 1_000_000));
    }

    private static function timeoutError(): GepettoException
    {
        return new GepettoException(
            'timeout',
            'The call took longer than its timeout. A render already started goes on: find it with listJobs().',
        );
    }
}
