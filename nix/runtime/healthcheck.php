#!/usr/bin/env php
<?php

/*
 * Liveness check for the php-fpm container. Exits 0 when the pool is accepting
 * connections, 1 when it is not.
 *
 * Why this exists rather than a `tcpSocket` probe in the manifest: the kubelet resolves
 * a TCP probe's target to the pod IP, not to the container's loopback, and the pool
 * binds 127.0.0.1 only (nix/runtime/fpm-conf.nix) precisely so that nothing outside the
 * pod's own containers can reach it. A tcpSocket probe against a loopback-bound pool
 * therefore fails against a perfectly healthy process and gets it restarted. Setting
 * the probe's `host` to 127.0.0.1 does not help either — that names the node's
 * loopback. An `exec` probe runs inside the container's network namespace, which is the
 * one place the right answer is available.
 *
 * What it proves is exactly what the tcpSocket probe proved: the master is alive and
 * the listen backlog is being served. It does not speak FastCGI, so it cannot tell a
 * pool whose workers are all wedged from a healthy one. The readiness probe on the web
 * container covers that case, because it renders a page through PHP; a FastCGI ping
 * here would be strictly better and is a refinement rather than a fix.
 */

$host = getenv('VICTUAL_FPM_HOST') ?: '127.0.0.1';
$port = (int) (getenv('VICTUAL_FPM_PORT') ?: 9000);

$errno = 0;
$errstr = '';
$socket = @fsockopen($host, $port, $errno, $errstr, 2.0);

if ($socket === false)
{
	fwrite(STDERR, "php-fpm is not accepting connections on $host:$port ($errno $errstr)" . PHP_EOL);
	exit(1);
}

fclose($socket);
exit(0);
