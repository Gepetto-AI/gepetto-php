<?php

declare(strict_types=1);

namespace Gepetto;

/**
 * A render. A tool of the client returns it once the image is ready: `output` is then its public URL.
 */
final readonly class Job
{
    public function __construct(
        public string $id,
        /** `pending`, `processing`, `success` or `error`. */
        public string $status,
        /** `declutter`, `furnish`, `sunshine`, `creative-redesign`, `surfaces`, `enhance` or `upscale`. */
        public string $tool,
        /** declutter and furnish: `auto` or `zone`; sunshine: the sky id; surfaces: `walls`, `floor` or `finish`;
         *  enhance: `straighten` or null; upscale: `2k` or `4k`; creative-redesign: null. */
        public ?string $mode,
        /** The image, once `status` is `success`. A public URL: copy the image to your storage if you keep it. */
        public ?string $output,
        /** @var array{code: string, message: string}|null */
        public ?array $error,
        /** Price in euro cents (0 with a test key; charged only on success). */
        public int $priceCents,
        public bool $test,
        public ?string $createdAt,
        public ?string $finishedAt,
    ) {
    }

    /** @param array<string, mixed> $data A job as the API sends it. */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            tool: (string) ($data['tool'] ?? ''),
            mode: isset($data['mode']) ? (string) $data['mode'] : null,
            output: isset($data['output']) ? (string) $data['output'] : null,
            error: is_array($data['error'] ?? null) ? $data['error'] : null,
            priceCents: (int) ($data['priceCents'] ?? 0),
            test: (bool) ($data['test'] ?? false),
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            finishedAt: isset($data['finishedAt']) ? (string) $data['finishedAt'] : null,
        );
    }

    /** Done, one way or the other: `success` or `error`. */
    public function isFinished(): bool
    {
        return $this->status === 'success' || $this->status === 'error';
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success' && $this->output !== null;
    }

    /** @return array<string, mixed> The job as the API sends it. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'tool' => $this->tool,
            'mode' => $this->mode,
            'output' => $this->output,
            'error' => $this->error,
            'priceCents' => $this->priceCents,
            'test' => $this->test,
            'createdAt' => $this->createdAt,
            'finishedAt' => $this->finishedAt,
        ];
    }
}
