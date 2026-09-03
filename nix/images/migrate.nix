# The migration tier: a Job or initContainer, not a server.
#
# It is the only image carrying migrations/, db/ and bin/, and it is the only workload
# that should ever hold a database role able to run DDL. ADR-0010's third property is
# "its own credential, its own database role, least privilege for the one job it does";
# splitting the image is what makes that possible to enforce rather than merely intend.
#
# Overriding Cmd runs a different tool from the same image — bin/victual-db-import for a
# grocy SQLite migration, for instance — and the entrypoint's preparation still happens.
{
  lib,
  dockerTools,
  php,
  appRoot,
  imageLib,
  version,

  dataPath ? "/data",
}:

let
  # The one place migrations/, db/ and bin/ survive the trip out of the app derivation.
  migrateRoot = appRoot.override { withMigrations = true; };
in
dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-migrate";

    contents = [
      migrateRoot
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
