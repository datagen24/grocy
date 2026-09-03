# The php-fpm master and pool configuration.
#
# One pool, static workers, TCP on loopback. TCP rather than a unix socket because the
# web tier is a second container in the same pod: containers in a pod share a network
# namespace but not a filesystem, so loopback is the seam that exists without inventing
# a shared writable volume. `listen.allowed_clients` pins it to that loopback so the
# pool is not reachable from elsewhere in the namespace even if something binds wider.
#
# `clear_env = no` is load-bearing and easy to lose: php-fpm clears the environment for
# workers by default, and every setting in config-dist.php is resolved through
# `Setting()`, which reads `getenv('VICTUAL_…')`. With the default, a ConfigMap full of
# VICTUAL_* variables is silently ignored and the application runs on its defaults —
# including DB_DRIVER=sqlite against a database that is not there.
{
  writeText,
  lib,
  # Workers are sized for a household instance, not for a fleet. `static` rather than
  # `dynamic` because the process count is then a fact rather than a behaviour, which
  # matters when the pod has a memory limit.
  workers ? 4,
  port ? 9000,
}:

writeText "victual-php-fpm.conf" ''
  [global]
  ; The master must stay in the foreground: it is PID 1's child and the container's
  ; liveness depends on it exiting when it dies.
  daemonize = no
  error_log = /proc/self/fd/2
  ; No pid file — there is nothing to signal it with, and it would want a writable path.
  log_limit = 8192

  [victual]
  listen = 127.0.0.1:${toString port}
  listen.allowed_clients = 127.0.0.1

  pm = static
  pm.max_children = ${toString workers}
  pm.max_requests = 500

  ; Without this the VICTUAL_* environment is dropped and every Setting() falls back to
  ; its default. See the header comment.
  clear_env = no

  ; Worker stdout/stderr goes to the master's stderr, undecorated, so a PHP warning
  ; arrives in the pod log as itself rather than wrapped in fpm's prefix.
  catch_workers_output = yes
  decorate_workers_output = no

  ; Access logging is the web tier's job; doing it twice doubles the log volume and
  ; tells you nothing new.
  access.log = /dev/null

  ; Refuse to execute anything that is not the front controller, whatever nginx was
  ; talked into passing. This is the second half of the "no PHP outside index.php"
  ; rule; the first half is the nginx location block.
  security.limit_extensions = .php

  ; Process-level function bans. These live here rather than in php.ini because the
  ; entrypoint runs under the same php.ini and needs pcntl_exec to hand off to fpm;
  ; a worker never does. Nothing in the tree calls any of these — services/PrintService
  ; reaches printers through file and network connectors, not through exec.
  php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,proc_close,proc_nice,proc_terminate,popen,pcntl_exec,pcntl_fork,dl,putenv
  php_admin_flag[allow_url_fopen] = off
''
