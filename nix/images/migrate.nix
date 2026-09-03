# The migration tier: a Job or initContainer, not a server.
#
# It is the only image carrying bin/victual-migrate and bin/victual-db-import, and it is
# the only workload that should ever hold a database role able to run DDL. ADR-0010's
# third property is "its own credential, its own database role, least privilege for the
# one job it does"; the credential split is what makes that enforceable rather than
# merely intended — the image split alone does not, because `migrations/` and `db/` are
# still read on the request path and so ship in every image (see nix/approot.nix).
#
# Overriding Cmd runs a different tool from the same image — bin/victual-db-import for a
# grocy SQLite migration, for instance — and the entrypoint's preparation still happens.
{
  lib,
  dockerTools,
  php,
  appRoot,
  configSeed,
  imageLib,
  version,

  dataPath ? "/data",
}:

let
  # The one application root that carries bin/victual-migrate and bin/victual-db-import.
  migrateRoot = appRoot.override { withCliTools = true; };
in
dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-migrate";

    contents = [
      migrateRoot
      configSeed
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold ''
      mkdir -p .${dataPath}
    '';

    config = imageLib.commonConfig // {
      Entrypoint = [
        "${php}/bin/php"
        "/app/entrypoint.php"
      ];

      Cmd = [
        "${php}/bin/php"
        "/app/bin/victual-migrate"
      ];

      Env = imageLib.commonConfig.Env ++ [
        "VICTUAL_DATAPATH=${dataPath}"
      ];

      Labels = imageLib.labels "migrate";
    };
  }
)
