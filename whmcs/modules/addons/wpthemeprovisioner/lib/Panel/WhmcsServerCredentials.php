<?php
namespace Wtp\Addon\Panel;

use Wtp\Addon\Support\Db;

/**
 * Reads server credentials from WHMCS's own `tblservers`.
 *
 * WHMCS stores the password and access hash encrypted with its CC encryption
 * key; `decrypt()` is the supported way to read them back and is available
 * once init.php has run. When it is not (the test suite, a CLI context without
 * WHMCS), the raw value is returned unchanged so a fake provider can be used.
 */
final class WhmcsServerCredentials implements ServerCredentialsProvider
{
    /** @var Db */
    private $db;
    /** @var array<int,array<string,mixed>|null> */
    private $cache = [];

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{hostname:string,ip:string,username:string,password:string,accesshash:string,secure:bool,port:int}|null
     */
    public function forServer(int $serverId): ?array
    {
        if (array_key_exists($serverId, $this->cache)) {
            /** @var array{hostname:string,ip:string,username:string,password:string,accesshash:string,secure:bool,port:int}|null */
            return $this->cache[$serverId];
        }

        $row = $this->db->selectOne(
            'SELECT id, ipaddress, hostname, username, password, accesshash, secure, port, disabled
             FROM tblservers WHERE id = :id',
            ['id' => $serverId]
        );

        if ($row === null) {
            $this->cache[$serverId] = null;
            return null;
        }

        $credentials = [
            'hostname' => (string) ($row['hostname'] !== '' ? $row['hostname'] : $row['ipaddress']),
            'ip' => (string) $row['ipaddress'],
            'username' => (string) $row['username'],
            'password' => self::reveal((string) $row['password']),
            'accesshash' => self::reveal((string) $row['accesshash']),
            'secure' => !empty($row['secure']),
            'port' => (int) $row['port'] > 0 ? (int) $row['port'] : WhmClient::DEFAULT_PORT,
        ];

        $this->cache[$serverId] = $credentials;
        return $credentials;
    }

    private static function reveal(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (function_exists('decrypt')) {
            $plain = decrypt($stored);
            if (is_string($plain) && $plain !== '') {
                return trim($plain);
            }
        }
        return trim($stored);
    }
}
