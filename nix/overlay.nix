# The one overlay this flake exports. Everything the flake builds hangs off
# `pkgs.victual`, so a consumer who wants one piece — the PHP build, say, or the
# webroot — gets it by adding this overlay rather than by importing files by path.
final: prev:

let
  inherit (final) lib;

  # The image tag and the derivation versions come from the same place the application
  # reports at /api/system/info. One version, one source.
  version = (builtins.fromJSON (builtins.readFile ../version.json)).Version;

  hashes = import ./hashes.nix;
  sources = import ./source.nix { inherit lib; };
in
{
  victual = lib.makeScope final.newScope (self: {
    inherit version hashes sources;

    # PHP 8.5 with exactly the extensions the application asks for and nothing else.
    php = self.callPackage ./php.nix { };

    # The application tree with its Composer dependencies resolved.
    app = self.callPackage ./app.nix { };

    # public/packages — the yarn-installed frontend libraries the Blade layout links.
    frontend = self.callPackage ./frontend.nix { };

    # What the web tier serves: the static half of public/, plus the frontend packages.
    webroot = self.callPackage ./webroot.nix { };

    # The application laid out at a fixed, non-store path. Both the app image and the
    # web image agree on /app so that nginx's SCRIPT_FILENAME resolves inside php-fpm.
    appRoot = self.callPackage ./approot.nix { };

    # Runtime configuration files, generated rather than hand-maintained so that a
    # change to a path is a change in one place.
    runtime = {
      # php-ini.nix is a bare string rather than a derivation: it is baked into the PHP
      # build (nix/php.nix) so that every SAPI in every image reads the same settings.
      fpmConf = self.callPackage ./runtime/fpm-conf.nix { };
      nginxConf = self.callPackage ./runtime/nginx-conf.nix { };
      entrypoint = ./runtime/entrypoint.php;
      healthcheck = ./runtime/healthcheck.php;
      configPhp = ./runtime/config.php;
    };

    # /etc/victual/config.php, as an image layer. Both PHP images carry it; the
    # entrypoint copies it into the data directory when nothing is mounted there.
    configSeed = self.callPackage ./config-seed.nix { };

    imageLib = self.callPackage ./images/lib.nix { };

    image-app = self.callPackage ./images/app.nix { };
    image-web = self.callPackage ./images/web.nix { };
    image-migrate = self.callPackage ./images/migrate.nix { };

    loadImages = self.callPackage ./images/load.nix { };

    checks = self.callPackage ./checks.nix { };
  });
}
