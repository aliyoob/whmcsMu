<?php
namespace Wtp\Addon\Panel;

/**
 * Supplies the credentials for a WHMCS server record.
 *
 * Behind an interface so the worker and the test suite build panels the same
 * way, and so a future deployment could read credentials from a vault instead
 * of tblservers without touching anything else.
 */
interface ServerCredentialsProvider
{
    /**
     * @return array{hostname:string,ip:string,username:string,password:string,accesshash:string,secure:bool,port:int}|null
     */
    public function forServer(int $serverId): ?array;
}
