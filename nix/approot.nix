# The application laid out at /app.
#
# Why a fixed path rather than the store path it already has: nginx tells php-fpm which
# file to execute by absolute path (SCRIPT_FILENAME), and those two run in different
# containers with different filesystems. They have to agree on a name. /app is that
# name, chosen rather than inherited, and it is the same in both images because both
# take it from here.
#
# It is a copy rather than a symlink farm because PHP resolves __DIR__ through the
# realpath cache: a symlinked public/index.php would report its store path, and
# `require_once __DIR__ . '/../app.php'` would then look for the application inside a
# read-only store path that does not contain the entrypoint. Copying costs a few
# megabytes in one layer and removes a whole class of surprise.
{
  lib,
  runCommand,
  app,
  php,
  version,
  sources,
  runtime,

  # The migrate image is the only one that invokes bin/victual-migrate or
  # bin/victual-db-import. The serving images do not, so they do not carry them.
  withCliTools ? false,
}:

runCommand "victual-approot-${version}${lib.optionalString withCliTools "-cli"}"
  {
    meta.description = "Victual application tree, laid out at /app";
  }
  ''
    mkdir -p "$out/app"
    cp -R --no-preserve=mode ${app}/share/php/victual/. "$out/app"/

    ${lib.optionalString (!withCliTools) ''
      # The CLI entry points are the migrate image's job. Nothing on the request path
      # invokes them.
      #
      # `migrations/` and `db/` deliberately stay, in every image. The first version of
      # this file stripped them on the theory that a migrated database makes them dead
      # weight, and that was wrong: `SystemController::Root` still calls
      # `MigrateDatabase()`, and `GetMigrationFiles()` opens a `FilesystemIterator` over
      # `migrations/` that throws when the directory is absent — so `/` answered 500
      # instead of its 302. PostgreSQL's baseline resolves to `db/pgsql/baseline` on the
      # same call path. They come out when plan 10 takes migration off the request path
      # and the schema-version check reads build-time metadata instead of counting
      # files.
      rm -rf ${lib.concatMapStringsSep " " (p: "$out/app/${p}") sources.cliPaths}
    ''}

    # The entrypoint sits beside the application rather than inside it, because it is
    # not part of the application — see the file's own header for when it is deleted.
    cp ${runtime.entrypoint} "$out/app/entrypoint.php"

    # The liveness probe. php-fpm speaks FastCGI rather than HTTP and listens only on
    # loopback, which the kubelet cannot reach — it probes the pod IP — so the check has
    # to run inside the container. These two paths are what the manifest's exec probe
    # names. The interpreter is a symlink rather than a copy because nothing resolves
    # __DIR__ through it; it is only ever argv[0].
    cp ${runtime.healthcheck} "$out/app/healthcheck.php"
    ln -s ${php}/bin/php "$out/app/php"

    chmod -R a-w,a+rX "$out/app"
  ''
