<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Container;

/**
 * GET /download?job&grant&exp&sig — stream a package.
 *
 * This is the only route authenticated by URL signature rather than by header,
 * because the agent hands the link to WordPress's own downloader, which will
 * not sign anything. The link is therefore made as weak as possible: bound to
 * one job, one grant and one short expiry, counted down on every use, and
 * useless without the checksum verification the agent performs afterwards.
 */
final class DownloadController
{
    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request): Response
    {
        $query = $request->queryAll();

        $uuid = isset($query['job']) ? (string) $query['job'] : '';
        $grantId = isset($query['grant']) ? (int) $query['grant'] : 0;

        $job = $this->container->jobs()->findByUuid($uuid);
        if ($job === null || $grantId <= 0) {
            throw ApiException::notFound('Unknown download');
        }
        $jobId = (int) $job['id'];

        // Signed with the job's derived download key, so the link keeps working
        // for the installer (which has no agent secret yet) and across a secret
        // rotation. The grant row is what bounds its authority.
        $signingKey = $this->container->jobs()->downloadKey($job);

        if (!$this->container->signedUrls()->verify($query, $signingKey)) {
            $this->container->eventLog()->warning($jobId, 'Rejected a download with an invalid signature', [
                'grant' => $grantId,
                'ip' => $request->ip(),
            ], null, 'invalid_signature');
            throw ApiException::forbidden('Invalid or expired download link');
        }

        $quota = $this->container->rateLimiter()->consume(
            'job:' . $jobId . ':download',
            60,
            $this->container->config()->getInt('rate_limit_window', 300)
        );
        if (!$quota['allowed']) {
            throw ApiException::rateLimited($quota['retry_after']);
        }

        $grant = $this->container->grants()->consume($grantId, $jobId);
        $storage = $this->container->storage();
        $path = (string) $grant['asset_ref'];

        if (!$storage->exists($path)) {
            $this->container->eventLog()->error($jobId, 'Granted package is missing from storage', [
                'grant' => $grantId,
                'path' => $path,
            ], null, 'storage_unreadable');
            throw new ApiException(503, 'asset_unavailable', 'The package is temporarily unavailable');
        }

        $size = $storage->size($path);
        $filename = (string) $grant['filename'];

        $this->container->eventLog()->info($jobId, 'Streaming a package to the agent', [
            'grant' => $grantId,
            'filename' => $filename,
            'size_bytes' => $size,
            'use' => (int) $grant['used_count'] . '/' . (int) $grant['max_uses'],
        ]);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="' . self::headerSafe($filename) . '"',
            'X-WTP-Sha256' => (string) $grant['sha256'],
            'X-WTP-Api' => Router::VERSION,
            'X-Content-Type-Options' => 'nosniff',
        ];

        return Response::stream(static function () use ($storage, $path): void {
            $storage->stream($path);
        }, $headers);
    }

    /** Strip anything that could break out of the Content-Disposition header. */
    private static function headerSafe(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._\- ]/', '_', basename($filename));
        $clean = substr((string) $clean, 0, 120);
        return $clean === '' ? 'package.bin' : $clean;
    }
}
