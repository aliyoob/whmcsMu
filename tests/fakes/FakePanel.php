<?php
namespace Wtp\Tests;

use Wtp\Addon\Panel\HostingPanel;
use Wtp\Addon\Panel\PanelException;

/**
 * A cPanel server that exists only in memory.
 *
 * Models the behaviours that actually matter to the provisioner: accounts that
 * already exist, files that persist between calls, and — importantly — the
 * failure modes. `failNext()` makes any operation throw once, which is how the
 * retry and classification paths get exercised without a real server.
 */
final class FakePanel implements HostingPanel
{
    /** @var array<string,array<string,mixed>> */
    private $accounts = [];
    /** @var array<string,string> "user:/path" => contents */
    private $files = [];
    /** @var array<string,array<string,string>> */
    private $databases = [];
    /** @var array<int,array{op:string,args:array<string,mixed>}> */
    public $calls = [];
    /** @var array<string,array{code:string,message:string,times:int}> */
    private $failures = [];
    /** @var bool */
    public $connectionOk = true;

    /**
     * Make the next $times calls to $operation fail.
     */
    public function failNext(string $operation, string $code, string $message, int $times = 1): void
    {
        $this->failures[$operation] = ['code' => $code, 'message' => $message, 'times' => $times];
    }

    public function testConnection(): array
    {
        $this->record('testConnection', []);
        if (!$this->connectionOk) {
            return ['ok' => false, 'version' => '', 'message' => 'WHM is unreachable'];
        }
        return ['ok' => true, 'version' => '110.0.15', 'message' => 'Connected to WHM 110.0.15'];
    }

    public function createAccount(array $spec): array
    {
        $this->record('createAccount', ['username' => $spec['username'], 'domain' => $spec['domain']]);
        $this->maybeFail('createAccount');

        $username = (string) $spec['username'];
        if (isset($this->accounts[$username])) {
            $existing = $this->accounts[$username];
            return [
                'username' => $username,
                'domain' => (string) $existing['domain'],
                'ip' => (string) $existing['ip'],
                'docroot' => (string) $existing['docroot'],
                'created' => false,
            ];
        }

        $this->accounts[$username] = [
            'domain' => (string) $spec['domain'],
            'ip' => (string) ($spec['ip'] ?: '198.51.100.10'),
            'docroot' => '/home/' . $username . '/public_html',
            'suspended' => false,
            'disk_used' => 12,
            'disk_limit' => 5000,
            'plan' => (string) ($spec['plan'] ?? ''),
        ];

        return [
            'username' => $username,
            'domain' => (string) $spec['domain'],
            'ip' => (string) $this->accounts[$username]['ip'],
            'docroot' => (string) $this->accounts[$username]['docroot'],
            'created' => true,
        ];
    }

    public function accountExists(string $username): bool
    {
        return isset($this->accounts[$username]);
    }

    public function accountSummary(string $username): array
    {
        $this->record('accountSummary', ['username' => $username]);
        $this->maybeFail('accountSummary');

        if (!isset($this->accounts[$username])) {
            throw new PanelException('Account not found: ' . $username, 'account_not_found');
        }
        $account = $this->accounts[$username];

        return [
            'username' => $username,
            'domain' => (string) $account['domain'],
            'ip' => (string) $account['ip'],
            'docroot' => (string) $account['docroot'],
            'suspended' => (bool) $account['suspended'],
            'disk_used' => (int) $account['disk_used'],
            'disk_limit' => (int) $account['disk_limit'],
        ];
    }

    public function suspendAccount(string $username, string $reason = ''): void
    {
        $this->record('suspendAccount', ['username' => $username]);
        $this->maybeFail('suspendAccount');
        if (isset($this->accounts[$username])) {
            $this->accounts[$username]['suspended'] = true;
        }
    }

    public function unsuspendAccount(string $username): void
    {
        $this->record('unsuspendAccount', ['username' => $username]);
        $this->maybeFail('unsuspendAccount');
        if (isset($this->accounts[$username])) {
            $this->accounts[$username]['suspended'] = false;
        }
    }

    public function terminateAccount(string $username, bool $keepDns = false): void
    {
        $this->record('terminateAccount', ['username' => $username]);
        $this->maybeFail('terminateAccount');
        unset($this->accounts[$username]);
    }

    public function changePackage(string $username, string $plan): void
    {
        $this->record('changePackage', ['username' => $username, 'plan' => $plan]);
        $this->maybeFail('changePackage');
        if (isset($this->accounts[$username])) {
            $this->accounts[$username]['plan'] = $plan;
        }
    }

    public function changePassword(string $username, string $password): void
    {
        $this->record('changePassword', ['username' => $username]);
        $this->maybeFail('changePassword');
    }

    public function provisionDatabase(string $account, string $database, string $dbUser, string $dbPassword): array
    {
        $this->record('provisionDatabase', ['account' => $account, 'database' => $database, 'user' => $dbUser]);
        $this->maybeFail('provisionDatabase');

        $this->databases[$account][$database] = $dbUser;
        return ['database' => $database, 'username' => $dbUser];
    }

    public function writeFile(string $account, string $directory, string $filename, string $contents): void
    {
        $this->record('writeFile', ['account' => $account, 'file' => $filename, 'bytes' => strlen($contents)]);
        $this->maybeFail('writeFile');

        $this->files[$account . ':' . rtrim($directory, '/') . '/' . $filename] = $contents;
    }

    public function deleteFile(string $account, string $path): void
    {
        $this->record('deleteFile', ['account' => $account, 'path' => $path]);
        unset($this->files[$account . ':' . $path]);
    }

    public function documentRoot(string $account): string
    {
        return isset($this->accounts[$account])
            ? (string) $this->accounts[$account]['docroot']
            : '/home/' . $account . '/public_html';
    }

    // ------------------------------------------------------------ assertions

    public function fileContents(string $account, string $path): ?string
    {
        return $this->files[$account . ':' . $path] ?? null;
    }

    /** @return string[] */
    public function fileNames(): array
    {
        return array_keys($this->files);
    }

    /** The installer configuration the deploy step wrote, decoded. */
    public function installerConfig(string $account): ?array
    {
        foreach ($this->files as $key => $contents) {
            if (strpos($key, $account . ':') !== 0 || substr($key, -5) !== '.json') {
                continue;
            }
            $decoded = json_decode($contents, true);
            if (is_array($decoded) && isset($decoded['run_token'])) {
                return $decoded;
            }
        }
        return null;
    }

    public function callCount(string $operation): int
    {
        $count = 0;
        foreach ($this->calls as $call) {
            if ($call['op'] === $operation) {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string,mixed> $args */
    private function record(string $operation, array $args): void
    {
        $this->calls[] = ['op' => $operation, 'args' => $args];
    }

    private function maybeFail(string $operation): void
    {
        if (!isset($this->failures[$operation])) {
            return;
        }
        $failure = $this->failures[$operation];
        if ($failure['times'] <= 0) {
            unset($this->failures[$operation]);
            return;
        }

        $this->failures[$operation]['times']--;
        if ($this->failures[$operation]['times'] <= 0) {
            unset($this->failures[$operation]);
        }

        throw new PanelException($failure['message'], $failure['code']);
    }
}
