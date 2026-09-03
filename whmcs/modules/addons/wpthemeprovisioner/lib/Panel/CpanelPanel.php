<?php
namespace Wtp\Addon\Panel;

/**
 * cPanel/WHM implementation of HostingPanel.
 *
 * Uses only functions that exist on every cPanel server:
 *   WHM API 1  : createacct, accountsummary, suspendacct, unsuspendacct,
 *                removeacct, changepackage, passwd, version
 *   cPanel UAPI: Mysql::create_database / create_user / set_privileges_on_database,
 *                Fileman::save_file_content, Fileman::get_file_information
 *   cPanel API2: Fileman::fileop  (extraction and deletion have no UAPI equivalent)
 *
 * Deliberately avoids Softaculous and WP Toolkit: neither is guaranteed to be
 * installed, and both version their APIs independently of us.
 */
final class CpanelPanel implements HostingPanel
{
    /** @var WhmClient */
    private $whm;
    /** @var array<string,string> cache of resolved document roots */
    private $docroots = [];

    public function __construct(WhmClient $whm)
    {
        $this->whm = $whm;
    }

    public function testConnection(): array
    {
        try {
            $data = $this->whm->whm('version');
            $version = isset($data['version']) ? (string) $data['version'] : 'unknown';

            // A connection that cannot list accounts is useless to us, so probe
            // the permission we actually need rather than just the handshake.
            $this->whm->whm('listaccts', ['searchtype' => 'user', 'search' => '^wtp-probe-never-matches$']);

            return ['ok' => true, 'version' => $version, 'message' => 'Connected to WHM ' . $version];
        } catch (PanelException $e) {
            return ['ok' => false, 'version' => '', 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $spec
     * @return array{username:string,domain:string,ip:string,docroot:string,created:bool}
     */
    public function createAccount(array $spec): array
    {
        $username = (string) $spec['username'];
        $domain = (string) $spec['domain'];

        // Idempotency at the panel boundary: if the account is already there we
        // adopt it rather than failing the job on a retry.
        if ($this->accountExists($username)) {
            $summary = $this->accountSummary($username);
            return [
                'username' => $username,
                'domain' => $summary['domain'],
                'ip' => $summary['ip'],
                'docroot' => $summary['docroot'],
                'created' => false,
            ];
        }

        $params = [
            'username' => $username,
            'domain' => $domain,
            'password' => (string) $spec['password'],
            'plan' => (string) ($spec['plan'] ?? ''),
            'contactemail' => (string) ($spec['contactemail'] ?? ''),
            'useregns' => 0,
            'reseller' => 0,
            'forcedns' => 0,
        ];
        if (!empty($spec['ip'])) {
            $params['ip'] = (string) $spec['ip'];
        }
        if (!empty($spec['locale'])) {
            $params['locale'] = (string) $spec['locale'];
        }
        $params = array_filter($params, static function ($v): bool {
            return $v !== '' && $v !== null;
        });
        // `useregns`, `reseller` and `forcedns` are legitimately 0.
        $params['useregns'] = 0;
        $params['reseller'] = 0;
        $params['forcedns'] = 0;

        $data = $this->whm->whm('createacct', $params);

        $ip = isset($data['ip']) ? (string) $data['ip'] : '';
        if ($ip === '' && isset($data['options']['ip'])) {
            $ip = (string) $data['options']['ip'];
        }

        $summary = $this->accountSummary($username);

        return [
            'username' => $username,
            'domain' => $summary['domain'] !== '' ? $summary['domain'] : $domain,
            'ip' => $ip !== '' ? $ip : $summary['ip'],
            'docroot' => $summary['docroot'],
            'created' => true,
        ];
    }

    public function accountExists(string $username): bool
    {
        try {
            $this->whm->whm('accountsummary', ['user' => $username]);
            return true;
        } catch (PanelException $e) {
            return false;
        }
    }

    /** @return array{username:string,domain:string,ip:string,docroot:string,suspended:bool,disk_used:int,disk_limit:int} */
    public function accountSummary(string $username): array
    {
        $data = $this->whm->whm('accountsummary', ['user' => $username]);

        $account = [];
        if (isset($data['acct'][0]) && is_array($data['acct'][0])) {
            $account = $data['acct'][0];
        } elseif (isset($data['acct']) && is_array($data['acct'])) {
            $account = $data['acct'];
        }

        return [
            'username' => (string) ($account['user'] ?? $username),
            'domain' => (string) ($account['domain'] ?? ''),
            'ip' => (string) ($account['ip'] ?? ''),
            'docroot' => $this->documentRootFrom($account, $username),
            'suspended' => !empty($account['suspended']),
            'disk_used' => (int) preg_replace('/\D/', '', (string) ($account['diskused'] ?? '0')),
            'disk_limit' => (int) preg_replace('/\D/', '', (string) ($account['disklimit'] ?? '0')),
        ];
    }

    public function suspendAccount(string $username, string $reason = ''): void
    {
        $params = ['user' => $username];
        if ($reason !== '') {
            $params['reason'] = substr($reason, 0, 191);
        }
        $this->whm->whm('suspendacct', $params);
    }

    public function unsuspendAccount(string $username): void
    {
        $this->whm->whm('unsuspendacct', ['user' => $username]);
    }

    public function terminateAccount(string $username, bool $keepDns = false): void
    {
        if (!$this->accountExists($username)) {
            return; // already gone; terminating twice must not fail a cancellation
        }
        $this->whm->whm('removeacct', ['user' => $username, 'keepdns' => $keepDns ? 1 : 0]);
    }

    public function changePackage(string $username, string $plan): void
    {
        $this->whm->whm('changepackage', ['user' => $username, 'pkg' => $plan]);
    }

    public function changePassword(string $username, string $password): void
    {
        $this->whm->whm('passwd', ['user' => $username, 'password' => $password, 'db_pass_update' => 0]);
    }

    /** @return array{database:string,username:string} */
    public function provisionDatabase(string $account, string $database, string $dbUser, string $dbPassword): array
    {
        // cPanel prefixes both names with the account; supplying an already
        // prefixed name is accepted, which is what makes this idempotent.
        $this->tolerateExisting(function () use ($account, $database): void {
            $this->whm->uapi($account, 'Mysql', 'create_database', ['name' => $database]);
        });

        $this->tolerateExisting(function () use ($account, $dbUser, $dbPassword): void {
            $this->whm->uapi($account, 'Mysql', 'create_user', [
                'name' => $dbUser,
                'password' => $dbPassword,
            ]);
        });

        $this->whm->uapi($account, 'Mysql', 'set_privileges_on_database', [
            'user' => $dbUser,
            'database' => $database,
            'privileges' => 'ALL PRIVILEGES',
        ]);

        return ['database' => $database, 'username' => $dbUser];
    }

    public function writeFile(string $account, string $directory, string $filename, string $contents): void
    {
        // UAPI first; API 2's savefile is the fallback for older cPanel builds.
        try {
            $this->whm->uapi($account, 'Fileman', 'save_file_content', [
                'dir' => $directory,
                'file' => $filename,
                'content' => $contents,
                'from_charset' => 'UTF-8',
                'to_charset' => 'UTF-8',
                'fallback' => 0,
            ]);
            return;
        } catch (PanelException $e) {
            if ($e->errorCode() === 'cpanel_auth_failed' || $e->errorCode() === 'network_error') {
                throw $e;
            }
        }

        $this->whm->api2($account, 'Fileman', 'savefile', [
            'dir' => $directory,
            'file' => $filename,
            'content' => $contents,
            'from_charset' => 'UTF-8',
            'to_charset' => 'UTF-8',
        ]);
    }

    public function deleteFile(string $account, string $path): void
    {
        $this->whm->api2($account, 'Fileman', 'fileop', [
            'op' => 'unlink',
            'sourcefiles' => $path,
            'doubledecode' => 0,
        ]);
    }

    public function documentRoot(string $account): string
    {
        if (isset($this->docroots[$account])) {
            return $this->docroots[$account];
        }
        $summary = $this->accountSummary($account);
        return $summary['docroot'];
    }

    /**
     * @param array<string,mixed> $account
     */
    private function documentRootFrom(array $account, string $username): string
    {
        foreach (['documentroot', 'docroot'] as $key) {
            if (!empty($account[$key])) {
                $root = rtrim((string) $account[$key], '/');
                $this->docroots[$username] = $root;
                return $root;
            }
        }
        $home = !empty($account['partition'])
            ? '/' . trim((string) $account['partition'], '/') . '/' . $username
            : '/home/' . $username;

        $root = $home . '/public_html';
        $this->docroots[$username] = $root;
        return $root;
    }

    /**
     * "Already exists" is success for our purposes — the whole pipeline is
     * built to be re-runnable, and a database that survived a previous attempt
     * is exactly the state we want.
     */
    private function tolerateExisting(callable $operation): void
    {
        try {
            $operation();
        } catch (PanelException $e) {
            if ($e->errorCode() !== 'account_exists_conflict') {
                throw $e;
            }
        }
    }
}
