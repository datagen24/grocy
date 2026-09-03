# The migration tier: a Job or initContainer, not a server.
#
# It is the only workload that should ever hold a database role able to run DDL, and the
# only image whose PHP carries pdo_sqlite — bin/victual-db-import reads SQLite as an
# import format, which is the one thing ADR-0008 keeps it for.
#
# What separates these workloads is the credential each holds, not the bytes each
# carries. The application tree is identical in all three images and is *meant* to be:
# `migrations/` and `db/` are read on the request path, and the baked view cache's
# compiled file names hash the absolute path of the views directory, so a second,
# trimmed application root would warm a cache the serving image could not use.
#
# Overriding Cmd runs a different tool from the same image — bin/victual-db-import for a
# grocy SQLite migration, for instance — and the entrypoint's preparation still happens.
{
  lib,
  dockerTools,
  phpMigrate,
  appRoot,
  configSeed,
  imageLib,
  runtime,
  version,

  dataPath ? "/data",
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-migrate";

    contents = [
      configSeed
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold ''
      mkdir -p .${dataPath}
    '';

    config = imageLib.commonConfig // {
      WorkingDir = appRoot;

      Entrypoint = [
        "${phpMigrate}/bin/php"
        "${runtime.entrypoint}"
      ];

      Cmd = [
        "${phpMigrate}/bin/php"
        "${appRoot}/bin/victual-migrate"
      ];

      Env = imageLib.commonConfig.Env ++ [
        "VICTUAL_DATAPATH=${dataPath}"
      ];

      Labels = imageLib.labels "migrate";
    };
  }
)
