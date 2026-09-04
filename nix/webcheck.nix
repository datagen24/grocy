# The web container's probe, at a fixed path.
#
# Companion to nix/healthcheck.nix, which does the same job for the app container. Both
# exist because a manifest can only name a path, and both are `exec` probes because that
# is the one probe shape `podman kube play` and the kubelet implement the same way — see
# nix/runtime/webcheck.c for what podman does with `httpGet` and why it cannot work here.
#
# `pkgsStatic`, so the binary has an empty runtime closure. That is the load-bearing part:
# a dynamically linked probe would put a libc — and whatever else came with it — into the
# tier whose entire argument is that it holds nothing but nginx and static files.
# nix/checks.nix asserts the web image's closure stays free of interpreters.
{
  pkgsStatic,
  version,
}:

pkgsStatic.stdenv.mkDerivation {
  pname = "victual-webcheck";
  inherit version;

  src = ./runtime/webcheck.c;

  dontUnpack = true;

  # -O2 and nothing clever. This is 150 lines of socket code that runs every two seconds
  # in a container with no shell; the interesting property is that it is boring.
  buildPhase = ''
    runHook preBuild
    $CC -O2 -Wall -Wextra -Werror -o webcheck $src
    runHook postBuild
  '';

  installPhase = ''
    runHook preInstall
    install -Dm555 webcheck "$out/opt/victual/webcheck"
    runHook postInstall
  '';

  meta.description = "In-container HTTP probe for Victual's nginx tier";
}
