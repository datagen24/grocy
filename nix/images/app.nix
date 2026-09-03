# The application tier: php-fpm and nothing else.
#
# It listens on loopback for FastCGI and never speaks HTTP. That is not a stylistic
# choice — it is what lets the web tier hold the document root and the app tier hold the
# database credential, with neither able to do the other's job.
#
# What is in the image: the PHP interpreter with the extensions nix/php.nix names, the
# application at /app, /etc/passwd for uid 65532, and CA roots. There is no shell, no
# package manager, no composer, no git. `kubectl exec … sh` will not work here, which is
# the intended cost — see docs/adr/0013-nix-built-container-images.md, "Consequences".
{
  lib,
  dockerTools,
  php,
  appRoot,
  imageLib,
  runtime,
  version,

  dataPath ? "/data",
  fpmPort ? 9000,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-app";

    contents = [
      appRoot
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold ''
      # A mount point, not a directory with anything in it. The deployment mounts an
      # emptyDir here (see deploy/README.md); an image left to write into its own
      # layer would be exactly the mutable-state problem this is meant to avoid.
      mkdir -p .${dataPath}
    '';

    config = imageLib.commonConfig // {
      # Entrypoint prepares the scratch directory and execs; Cmd is what it execs.
      # Splitting them this way means `podman run --entrypoint …` is not needed to run
      # one of the CLI tools — overriding Cmd is enough, and the preparation still
      # happens.
      Entrypoint = [
        "${php}/bin/php"
        "/app/entrypoint.php"
      ];

      Cmd = [
        "${php}/bin/php-fpm"
        "--nodaemonize"
        "--fpm-config"
        "${runtime.fpmConf}"
      ];

      Env = imageLib.commonConfig.Env ++ [
        "VICTUAL_DATAPATH=${dataPath}"
      ];

      ExposedPorts = {
        "${toString fpmPort}/tcp" = { };
      };

      # php-fpm treats SIGQUIT as "finish the requests you have, then stop" and SIGTERM
      # as "stop now". Docker and Podman honour this field. Kubernetes does not read it
      # — it sends SIGTERM unless the pod sets `lifecycle.stopSignal` — so the manifest
      # has to say the same thing again; deploy/README.md says so.
      StopSignal = "SIGQUIT";

      Labels = imageLib.labels "app";
    };
  }
)
