# Nix flake for Victual's container images.
#
# What this builds and why it is not the Dockerfile: the `Dockerfile` at the repository
# root is a *development and CI* image — a full Debian with a compiler toolchain, git,
# composer and a shell, so a contributor can run the differential suite from a clean
# checkout. It is deliberately fat. These images are the other thing: production
# artifacts with nothing in them that the running process does not need, built from a
# pinned dependency graph rather than from whatever `apt-get update` returns today.
#
# See docs/adr/0013-nix-built-container-images.md for the decision and
# nix/README.md for how to build them (including the part where a Mac cannot build a
# Linux image without a Linux builder).
{
  description = "Victual — minimal, reproducible container images built with Nix";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
  };

  outputs =
    { self, nixpkgs }:
    let
      inherit (nixpkgs) lib;

      # Container images are Linux artifacts. Everything else in this flake — the
      # devShell, the application derivation itself — evaluates on a Mac too, which is
      # the difference between "you can work on this from a laptop" and "you cannot".
      linuxSystems = [
        "x86_64-linux"
        "aarch64-linux"
      ];
      darwinSystems = [
        "x86_64-darwin"
        "aarch64-darwin"
      ];
      allSystems = linuxSystems ++ darwinSystems;

      forSystems = systems: f: lib.genAttrs systems (system: f system);

      pkgsFor =
        system:
        import nixpkgs {
          inherit system;
          overlays = [ self.overlays.default ];
        };
    in
    {
      overlays.default = import ./nix/overlay.nix;

      packages = forSystems allSystems (
        system:
        let
          pkgs = pkgsFor system;
          v = pkgs.victual;
        in
        {
          inherit (v)
            php
            app
            frontend
            webroot
            appRoot
            ;
        }
        // lib.optionalAttrs (builtins.elem system linuxSystems) {
          inherit (v)
            image-app
            image-web
            image-migrate
            ;
          # `nix build` with no attribute gives the thing most people want first.
          default = v.image-app;
        }
      );

      apps = forSystems linuxSystems (
        system:
        let
          pkgs = pkgsFor system;
        in
        {
          # `nix run .#load` streams all three images straight into podman without
          # writing a tarball anywhere. streamLayeredImage produces a script that
          # writes the tar to stdout, which is exactly what `podman load` wants.
          load = {
            type = "app";
            program = lib.getExe pkgs.victual.loadImages;
          };
          default = self.apps.${system}.load;
        }
      );

      checks = forSystems linuxSystems (system: (pkgsFor system).victual.checks);

      devShells = forSystems allSystems (
        system:
        let
          pkgs = pkgsFor system;
        in
        {
          default = pkgs.mkShellNoCC {
            packages = [
              pkgs.victual.php
              pkgs.victual.php.packages.composer
              pkgs.yarn
              pkgs.nodejs
              pkgs.nix-prefetch-git
            ];
            shellHook = ''
              echo "Victual dev shell — PHP $(php -r 'echo PHP_VERSION;')"
            '';
          };
        }
      );

      formatter = forSystems allSystems (system: (pkgsFor system).nixfmt-tree);
    };
}
