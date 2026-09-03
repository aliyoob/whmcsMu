<?php
namespace Wtp\Addon\Panel;

/**
 * Everything the provisioner needs from a hosting control panel.
 *
 * Nothing above this interface knows what cPanel is. Adding DirectAdmin or
 * Plesk means writing one more implementation and a config option — the job
 * pipeline, the API and the agent are untouched.
 */
interface HostingPanel
{
    /** @return array{ok:bool,version:string,message:string} */
    public function testConnection(): array;

    /**
     * @param array<string,mixed> $spec username, domain, password, plan, contactemail, ip, locale
     * @return array{username:string,domain:string,ip:string,docroot:string,created:bool}
     */
    public function createAccount(array $spec): array;

    public function accountExists(string $username): bool;

    /** @return array{username:string,domain:string,ip:string,docroot:string,suspended:bool,disk_used:int,disk_limit:int} */
    public function accountSummary(string $username): array;

    public function suspendAccount(string $username, string $reason = ''): void;

    public function unsuspendAccount(string $username): void;

    public function terminateAccount(string $username, bool $keepDns = false): void;

    public function changePackage(string $username, string $plan): void;

    public function changePassword(string $username, string $password): void;

    /**
     * Create a database, a user and the grant between them.
     *
     * @return array{database:string,username:string}
     */
    public function provisionDatabase(string $account, string $database, string $dbUser, string $dbPassword): array;

    /** Write a small text file into the account's home-relative directory. */
    public function writeFile(string $account, string $directory, string $filename, string $contents): void;

    public function deleteFile(string $account, string $path): void;

    /** Absolute document root for the account's primary domain. */
    public function documentRoot(string $account): string;
}
