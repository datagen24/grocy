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
  version,
  sources,
  runtime,

  # The serving images do not run migrations and do not need the corpus. The migrate
  # image overrides this.
  withMigrations ? false,
}:

runCommand "victual-approot-${version}${lib.optionalString withMigrations "-migrations"}"
  {
    meta.description = "Victual application tree, laid out at /app";
  }
  ''
    mkdir -p "$out/app"
    cp -R --no-preserve=mode ${app}/share/php/victual/. "$out/app"/

    ${lib.optionalString (!withMigrations) ''
      # Not the request path's business: the DDL corpus and the CLI entry points that
      # apply it. They live in the migrate image, which is the only thing that holds a
      # credential able to run them.
      rm -rf ${lib.concatMapStringsSep " " (p: "$out/app/${p}") sources.migrationPaths}
    ''}

    # The entrypoint sits beside the application rather than inside it, because it is
    # not part of the application — see the file's own header for when it is deleted.
    cp ${runtime.entrypoint} "$out/app/entrypoint.php"

    chmod -R a-w,a+rX "$out/app"
  ''
