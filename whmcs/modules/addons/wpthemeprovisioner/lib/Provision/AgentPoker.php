<?php
namespace Wtp\Addon\Provision;

use Wtp\Addon\Container;
use Wtp\Addon\Job\JobException;
use Wtp\Addon\Support\HttpClient;
use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Support\Json;

/**
 * Wakes the WordPress agent up.
 *
 * This exists because of one stubborn fact: a WordPress site that nobody has
 * visited never runs WP-Cron. A brand-new customer site has exactly zero
 * traffic, so an agent that waited to be scheduled would wait forever.
 *
 * WHMCS therefore pokes the site directly. The request is signed with the same
 * HMAC scheme the agent uses outbound — before the handshake with the
 * single-use bootstrap token, afterwards with the job secret — so an
 * unauthenticated third party cannot make a customer's site hammer the billing
 * server.
 */
final class AgentPoker
{
    private const AJAX_PATH = '/wp-admin/admin-ajax.php';
    private const BODY = 'action=wtp_tick';

    /** @var Container */
    private $container;
    /** @var HttpClient */
    private $http;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->http = $container->httpClient();
    }

    /**
     * @param array<string,mixed> $job
     * @return array{poked:bool,status:int,phase:string,error:string}
     */
    public function poke(array $job): array
    {
        $domain = (string) $job['domain'];
        if ($domain === '') {
            return $this->outcome(false, 0, '', 'the job has no domain');
        }

        $headers = $this->authHeaders($job);
        if ($headers === null) {
            return $this->outcome(false, 0, '', 'no usable credentials for this job');
        }
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $headers['Accept'] = 'application/json';

        $payload = Json::tryDecode($job['payload'] ?? null, []);
        $ip = (string) ($payload['cpanel']['ip'] ?? '');

        // Same reasoning as the installer: DNS may not point here yet, and the
        // certificate may not exist. Try TLS first anyway so the signature is
        // not exposed to passive observers, then fall back.
        foreach ([['https', true], ['http', false]] as $attempt) {
            $options = [
                'body' => self::BODY,
                'headers' => $headers,
                'timeout' => 25,
                'insecure' => $attempt[1],
            ];
            if ($ip !== '') {
                $options['resolve'] = $ip;
            }

            $response = $this->http->post($attempt[0] . '://' . $domain . self::AJAX_PATH, $options);

            if ($response['error'] !== '') {
                continue;
            }

            $status = (int) $response['status'];
            $decoded = Json::tryDecode($response['body'], []);
            $phase = (string) ($decoded['phase'] ?? '');

            if ($status === 401) {
                return $this->outcome(false, $status, $phase, 'the agent rejected our signature');
            }
            if ($status >= 200 && $status < 300) {
                return $this->outcome(true, $status, $phase, '');
            }
            return $this->outcome(false, $status, $phase, 'the site answered HTTP ' . $status);
        }

        return $this->outcome(false, 0, '', 'the site could not be reached');
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,string>|null
     */
    private function authHeaders(array $job): ?array
    {
        // After the handshake the agent has a secret and we sign like it does.
        try {
            $secret = $this->container->jobs()->agentSecret($job);

            return (new HmacSigner($this->container->clock()))->sign(
                $secret,
                (string) $job['uuid'],
                'POST',
                self::AJAX_PATH,
                self::BODY
            );
        } catch (JobException $e) {
            unset($e); // no secret yet — fall through to the bootstrap token
        }

        $payload = Json::tryDecode($job['payload'] ?? null, []);
        $sealed = $payload['bootstrap']['token_enc'] ?? null;
        if ($sealed === null) {
            return null;
        }

        $token = $this->container->jobSecrets()->tryOpen((string) $sealed, 'bootstrap-token');
        if ($token === '') {
            return null;
        }

        return ['Authorization' => 'WTP-Bootstrap ' . (string) $job['uuid'] . '.' . $token];
    }

    /** @return array{poked:bool,status:int,phase:string,error:string} */
    private function outcome(bool $poked, int $status, string $phase, string $error): array
    {
        return ['poked' => $poked, 'status' => $status, 'phase' => $phase, 'error' => $error];
    }
}
