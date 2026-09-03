<?php
namespace Wtp\Addon\Panel;

use Wtp\Addon\Support\HttpClient;

/**
 * Builds a HostingPanel for a WHMCS server record.
 *
 * The only place in the system that knows which panel driver to use; adding
 * DirectAdmin means one more branch here and one more class.
 */
final class PanelFactory
{
    /** @var ServerCredentialsProvider */
    private $credentials;
    /** @var HttpClient */
    private $http;
    /** @var array<int,HostingPanel> */
    private $cache = [];

    public function __construct(ServerCredentialsProvider $credentials, HttpClient $http)
    {
        $this->credentials = $credentials;
        $this->http = $http;
    }

    /** @throws PanelException when the server record is missing or unusable */
    public function forServer(int $serverId): HostingPanel
    {
        if (isset($this->cache[$serverId])) {
            return $this->cache[$serverId];
        }

        $credentials = $this->credentials->forServer($serverId);
        if ($credentials === null) {
            throw new PanelException('WHMCS server ' . $serverId . ' does not exist', 'server_not_found');
        }
        if ($credentials['hostname'] === '') {
            throw new PanelException('WHMCS server ' . $serverId . ' has no hostname or IP', 'server_misconfigured');
        }
        if ($credentials['accesshash'] === '' && $credentials['password'] === '') {
            throw new PanelException(
                'WHMCS server ' . $serverId . ' has neither an API token nor a password',
                'cpanel_auth_failed'
            );
        }

        $panel = new CpanelPanel(new WhmClient(
            $this->http,
            $credentials['hostname'],
            $credentials['username'] !== '' ? $credentials['username'] : 'root',
            $credentials['accesshash'],
            $credentials['password'],
            $credentials['port'],
            true
        ));

        $this->cache[$serverId] = $panel;
        return $panel;
    }

    /** Test seam: inject a fake panel for a server id. */
    public function set(int $serverId, HostingPanel $panel): void
    {
        $this->cache[$serverId] = $panel;
    }
}
