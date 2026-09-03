# `nix run .#load` — build all three images and stream them into podman.
#
# streamLayeredImage produces a script that writes an OCI tarball to stdout rather than
# a tarball in the store, so nothing the size of an image is ever written to disk twice.
# That is the difference between a build that costs a gigabyte of store and one that
# costs the layers podman keeps.
{
  lib,
  writeShellApplication,
  coreutils,
  image-app,
  image-web,
  image-migrate,
  version,
}:

writeShellApplication {
  name = "victual-load-images";

  runtimeInputs = [ coreutils ];

  text = ''
    engine="''${CONTAINER_ENGINE:-podman}"

    if ! command -v "$engine" >/dev/null 2>&1; then
      echo "victual-load-images: '$engine' is not on PATH." >&2
      echo "Set CONTAINER_ENGINE=docker if that is what you run." >&2
      exit 1
    fi

    for streamer in ${image-app} ${image-web} ${image-migrate}; do
      echo "==> $(basename "$streamer")" >&2
      "$streamer" | "$engine" load
    done

    echo >&2
    echo "Loaded victual-app:${version}, victual-web:${version} and victual-migrate:${version}." >&2
    echo "deploy/podman/victual.yaml expects exactly those tags." >&2
  '';
}
