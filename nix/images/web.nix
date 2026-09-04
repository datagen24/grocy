# The static tier: nginx, the document root, and no application code at all.
#
# The image contains no PHP interpreter, so the worst outcome of a path-traversal bug in
# the nginx config is that somebody reads a stylesheet. The `.php` location block passes
# exactly one filename to the app tier and 404s everything else; the document root
# nix/webroot.nix builds has had index.php removed from it, so there is nothing here to
# leak as source even if that block were wrong.
{
  lib,
  dockerTools,
  nginx,
  webroot,
  webcheckBin,
  imageLib,
  runtime,
  version,

  listenPort ? 8080,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-web";

    # nginx itself is not listed here on purpose. The Entrypoint names its absolute
    # store path, which makes it a closure root through the image's config, so the
    # binary is present without also being symlinked into /bin. An image with an empty
    # /bin is one fewer thing for an attacker to find by looking.
    #
    # webcheckBin is listed, because unlike nginx it has to land at a path the *manifest*
    # can name: /opt/victual/webcheck. It is statically linked and brings nothing with it.
    contents = [
      webcheckBin
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold "";

    config = imageLib.commonConfig // {
      WorkingDir = "/tmp";

      # -p /tmp because nginx resolves a handful of paths against its prefix and the
      # compiled-in prefix is a read-only store path. Everything else in the
      # configuration is absolute. -e is the *pre-configuration* error log: without it,
      # a config nginx cannot parse is reported into a file under the prefix that
      # nobody will ever read.
      Entrypoint = [
        "${nginx}/bin/nginx"
        "-p"
        "/tmp"
        "-e"
        "/dev/stderr"
        "-c"
        "${runtime.nginxConf}"
      ];

      ExposedPorts = {
        "${toString listenPort}/tcp" = { };
      };

      StopSignal = "SIGQUIT";

      Labels = imageLib.labels "web";
    };
  }
)
