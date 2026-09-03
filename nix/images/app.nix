# The application tier: php-fpm and nothing else.
#
# It listens on loopback for FastCGI and never speaks HTTP. That is not a stylistic
# choice — it is what lets the web tier hold the document root and the app tier hold the
# database credential, with neither able to do the other's job.
#
# What is in the image: the PHP interpreter with the extensions nix/php.nix names, the
# application and its baked view cache at their store path, the config stub at
# /etc/victual/config.php, /etc/passwd for uid 65532, and CA roots. There is no shell, no
# package manager, no composer, no git. `kubectl exec … sh` will not work here, which is
# the intended cost — see docs/adr/0013-nix-built-container-images.md, "Consequences".
#
# Note how the application gets into the image: not through `contents`, which would
# splice its `share/` into `/`, but through the store path named in `Env` below.
# streamLayeredImage takes its closure roots from the image config as well as from
# `contents`, so naming the view cache is enough to pull the whole tree in.
{
  lib,
  dockerTools,
  php,
  app,
  appRoot,
  configSeed,
  healthcheckBin,
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
      configSeed
      healthcheckBin
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold ''
      # A mount point, not a directory with anything in it. The deployment mounts an
      # emptyDir here (see deploy/README.md).
      mkdir -p .${dataPath}
    '';

    config = imageLib.commonConfig // {
      WorkingDir = appRoot;

      # Entrypoint seeds config.php and execs; Cmd is what it execs. Splitting them this
      # way means overriding Cmd runs a different tool from the same image and the
      # seeding still happens.
      Entrypoint = [
        "${php}/bin/php"
        "${runtime.entrypoint}"
      ];

      Cmd = [
        "${php}/bin/php-fpm"
        "--nodaemonize"
        "--fpm-config"
        "${runtime.fpmConf}"
      ];

      Env = imageLib.commonConfig.Env ++ [
        "VICTUAL_DATAPATH=${dataPath}"

        # Baked at build time by nix/app.nix, read-only, and the reason this image needs
        # no writable path outside ${dataPath}. This is also the reference that puts the
        # application closure into the image.
        "VICTUAL_VIEWCACHE_PATH=${app}/share/php/victual/viewcache"
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
