# public/packages — the frontend libraries the Blade layout links.
#
# yarn v1 with `--modules-folder public/packages`, which is what .yarnrc configures for
# a working tree. Here the flags are passed explicitly and .yarnrc is kept out of the
# source, so the build does not depend on a file whose job is to configure somebody's
# laptop.
#
# One of the thirty-odd dependencies (@danielfarrell/bootstrap-combobox) resolves to a
# git URL pinned to a commit. `fetchYarnDeps` handles that — its `isGitUrl` matcher
# covers `https://….git#<ref>` and it prefetches through nix-prefetch-git — but it is
# the single most likely thing in this file to break on a nixpkgs bump, so it is named
# here rather than discovered later.
{
  lib,
  stdenvNoCC,
  fetchYarnDeps,
  fixup-yarn-lock,
  yarn,
  nodejs,
  hashes,
  sources,
  version,
}:

stdenvNoCC.mkDerivation {
  pname = "victual-frontend";
  inherit version;

  src = sources.toSource sources.yarnFiles;

  offlineCache = fetchYarnDeps {
    yarnLock = ../yarn.lock;
    hash = hashes.yarnOfflineCache;
  };

  nativeBuildInputs = [
    yarn
    fixup-yarn-lock
    nodejs
  ];

  dontConfigure = true;

  buildPhase = ''
    runHook preBuild

    export HOME="$NIX_BUILD_TOP/home"
    mkdir -p "$HOME"

    yarn config --offline set yarn-offline-mirror "$offlineCache"
    fixup-yarn-lock yarn.lock

    # The flags mirror .yarnrc: production dependencies only, no lifecycle scripts (a
    # postinstall in a frontend package is an arbitrary-code seam this image does not
    # need), no optional dependencies.
    yarn install \
      --offline \
      --frozen-lockfile \
      --production \
      --ignore-scripts \
      --ignore-optional \
      --ignore-platform \
      --ignore-engines \
      --no-progress \
      --non-interactive \
      --modules-folder "$out/packages"

    runHook postBuild
  '';

  # yarn writes an integrity/state file into the modules folder; it records the machine
  # that produced the tree and nothing reads it at runtime.
  installPhase = ''
    runHook preInstall

    rm -f "$out/packages/.yarn-integrity"
    find "$out/packages" -name '.bin' -type d -prune -exec rm -rf {} + || true

    runHook postInstall
  '';

  dontFixup = true;

  meta = {
    description = "Frontend libraries served from Victual's /packages";
    platforms = lib.platforms.all;
  };
}
