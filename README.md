# Gepetto PHP SDK

[![Packagist](https://img.shields.io/packagist/v/gepetto/sdk)](https://packagist.org/packages/gepetto/sdk) [![Tests](https://github.com/Gepetto-AI/gepetto-php/actions/workflows/tests.yml/badge.svg)](https://github.com/Gepetto-AI/gepetto-php/actions/workflows/tests.yml)

The official PHP SDK of the [Gepetto API v2](https://docs.gepettoapp.com): real estate photo editing. Enhance a photo, declutter a room, furnish it, change the sky, restyle it, repaint walls and floors, upscale. One call, an image URL in return.

```bash
composer require gepetto/sdk
```

PHP 8.2 or later, with the curl extension. No other dependency.

## Quickstart

```php
use Gepetto\Gepetto;

$gepetto = new Gepetto(); // reads GEPETTO_API_KEY

$job = $gepetto->furnish([
    'url' => 'https://assets.gepettoapp.com/raw-images/b85e2aa5-ac29-44a0-93eb-2de0a40f7905.png',
    'roomId' => 'living_room',
    'styleId' => 'scandinave',
]);

echo $job->output; // https://assets.gepettoapp.com/api/v2/jobs/.../output.jpg
```

No key yet? A free test key (200 real renders, no watermark) in two commands:

```bash
npx @gepetto/cli login   # opens a page: check the code, click "Authorize"
npx @gepetto/cli init    # writes GEPETTO_API_KEY to .env
```

The key can also be given directly: `new Gepetto('test_...')`, or `new Gepetto(['apiKey' => ..., 'timeout' => 120])`.

In Laravel, put the key in `.env` and in `config/services.php` (`'gepetto' => ['key' => env('GEPETTO_API_KEY')]`), then:

```php
$this->app->singleton(Gepetto::class, fn () => new Gepetto(config('services.gepetto.key')));
```

## What you get

- **The method waits for the image.** Each tool returns the finished `Job`, whose `output` is the image URL. A failed render throws a `GepettoException`, and is never charged.
- **Your editor knows the parameters.** Every tool documents the shape of its array: PhpStorm, VS Code (Intelephense) and PHPStan complete and check the keys.
- **Retries are safe.** Every render is sent with an idempotency key (yours, or one generated). A network error, a `429` or a `5xx` is retried with the same key: a render is never started, nor billed, twice.
- **Long renders just work.** If the connection drops while the image is being made, the SDK picks up the same job and waits for it.
- **The names are those of the API.** What the [docs](https://docs.gepettoapp.com) say of an endpoint is true of the method: same fields, same ids, same error codes.

## Tools

The `url` of every tool is a public http(s) URL of a JPEG, PNG, WebP or HEIC image: upload the photo to your own storage first. The output URL is public too; copy the image if you keep it.

| Method | What it does |
| --- | --- |
| `enhance(['url' => ..., 'straighten' => true])` | Light and colours corrected at the size of the photo, nothing redrawn |
| `declutter(['url' => ..., 'keepFireplace' => true])` | The whole room emptied (also `keepKitchen`, `keepBathroom`) |
| `declutter(['url' => ..., 'mask' => ...])` | What the mask covers removed |
| `furnish(['url' => ..., 'roomId' => ..., 'styleId' => ...])` | The whole room furnished |
| `furnish(['url' => ..., 'mask' => ..., 'roomId' => ..., 'styleId' => ...])` | The zone of the mask furnished as a room |
| `furnish(['url' => ..., 'mask' => ..., 'furnitureId' => ...])` | One piece of furniture in the zone (`styleId` optional) |
| `sunshine(['url' => ..., 'skyId' => 'sunny'])` | A blue sky and the matching light (also `lightProjection`, `poolLight`, `addLeafs`) |
| `creativeRedesign(['url' => ..., 'roomId' => ..., 'styleId' => ...])` | The room redesigned in a style (`preserveFloor` to keep the floor) |
| `surfaces(['url' => ..., 'surface' => 'walls', 'wallType' => ...])` | Walls repainted (`'custom'` with `customWallColor`, `preserveCeiling`) |
| `surfaces(['url' => ..., 'surface' => 'floor', 'floorType' => ...])` | Floor changed (`includeBaseboard`) |
| `surfaces(['url' => ..., 'surface' => 'finish', 'finishId' => ...])` | A finish applied: clean floor, clean pool, mowed lawn... |
| `upscale(['url' => ..., 'resolution' => '4k'])` | Enlarged to about 4 (`2k`) or 16 megapixels (`4k`, default) |

**Masks.** A mask is a black image with the zone painted in white, drawn on the photo. Give its URL, or the image itself in base64: nothing to host.

```php
$job = $gepetto->declutter([
    'url' => $photoUrl,
    'mask' => Gepetto::maskFromFile('/path/to/mask.png'), // or a data URL from canvas.toDataURL('image/png')
]);
```

Every tool takes a second argument:

```php
$gepetto->enhance(['url' => $photoUrl, 'straighten' => true], [
    'idempotencyKey' => "{$photo->id}:enhance", // one key per photo and per tool
    'timeout' => 120,                           // seconds, for this call (default: the client's, 10 minutes)
]);
```

## Lists

The ids are not guessable: they come from the API.

```php
$gepetto->listRooms();      // roomId, with the modes of each room ('auto': whole room, 'zone': with a mask)
$gepetto->listStyles();     // styleId
$gepetto->listFurnitures(); // furnitureId, grouped by type
$gepetto->listSkies();      // skyId
$gepetto->listSurfaces();   // ['walls' => ..., 'floors' => ..., 'finishes' => ...]
```

## Errors

Every error is a `GepettoException`. `errorCode` is stable, for your code; the message says what to do next.

```php
use Gepetto\GepettoException;

try {
    $job = $gepetto->furnish(['url' => $photoUrl, 'roomId' => 'bedroom', 'styleId' => 'modrn']);
} catch (GepettoException $e) {
    $e->errorCode;    // "unknown_style"
    $e->param;        // "styleId"
    $e->status;       // 400 (also $e->getCode())
    $e->getMessage(); // No style "modrn". The list is on GET /v2/styles.
    $e->jobId;        // set when a render was started (failed render, timeout)
}
```

The codes are listed in the [docs](https://docs.gepettoapp.com) (Errors). The SDK adds `timeout` and `connection_error`.

## Webhooks

To not wait for the image in the request, send `webhook`: the call returns at once with the pending job, and Gepetto sends the finished job to your URL, signed.

```php
$job = $gepetto->furnish([..., 'webhook' => 'https://your-site.com/gepetto/webhook']);
// $job->status === 'pending'
```

```php
use Gepetto\Webhook;

// Your webhook route. The secret is read from GEPETTO_WEBHOOK_SECRET (or pass it as a third argument):
// it is webhook_secret of $gepetto->account(), or `npx @gepetto/cli secret`
$job = Webhook::verify(
    file_get_contents('php://input'),
    $_SERVER['HTTP_GEPETTO_SIGNATURE'] ?? null,
);

if ($job->isSuccess()) {
    // $job->output
}
```

`Webhook::verify` throws `invalid_signature` when the request does not come from Gepetto. Give it the raw body, before any JSON decoding.

## Jobs, account

```php
$gepetto->getJob($id);                 // a job, finished or not
$gepetto->waitForJob($id);             // waits for a job started with a webhook
$gepetto->listJobs(['status' => 'error', 'limit' => 10]);
$gepetto->account();                   // key type, test renders used, webhook secret
$gepetto->usage();                     // consumption of the current period
```

## Options

```php
new Gepetto([
    'apiKey' => '...',       // default: GEPETTO_API_KEY
    'timeout' => 600,        // seconds a call waits for its image, retries included
    'maxRetries' => 2,       // after a network error, a 429 or a 5xx
    'transport' => $callable, // your own HTTP layer: fn ($method, $url, $headers, $body, $timeout): ['status' => int, 'body' => string]
]);
```

Keep the key on your server: never in a web page or a mobile app.

## About Gepetto

Gepetto is the AI photo studio for real estate professionals. From a single photo, it furnishes an empty room, empties a cluttered one, restyles an interior, replaces a grey sky, repaints walls, changes floors, enhances the picture and animates the property on video, in seconds and with no technical skill. Launched in January 2023 in Bordeaux, France, Gepetto is used by more than 1,000 agencies, networks and real estate photographers, on the web, on iPhone and Android, and through this API inside real estate CRMs and platforms.

[gepettoapp.com](https://gepettoapp.com) · [API documentation](https://docs.gepettoapp.com) · [TypeScript SDK](https://github.com/Gepetto-AI/gepetto-sdk) · [CLI](https://github.com/Gepetto-AI/gepetto-cli)

## License

MIT
