# What `nix flake check` proves.
#
# ADR-0010's open question 2 leans towards "start with the cheap greps — CI fails a
# Dockerfile with no USER" and adopting a real linter only when the cheap version misses
# something. These are the cheap greps, except that Nix lets them be assertions about
# the *artifact* rather than about the file that describes it: "the image config
# declares a non-root user" is a stronger statement than "the Dockerfile contains the
# string USER", and it costs the same.
#
# None of these boot a container. The checks that need a running instance — does the
# read-only root filesystem hold, does every page render, which extensions actually got
# loaded — are in plan 20's verification section, because they need a Linux host with a
# container runtime and this evaluates on a laptop.
{
  lib,
  runCommand,
  closureInfo,
  jq,

  php,
  appRoot,
  webroot,
  imageLib,
  version,
}:

let
  # streamLayeredImage's passthru does not expose the config, so the assertion is made
  # against the same value the image was built from. That is weaker than reading it back
  # out of the artifact and honest about being so: it catches an edit that drops the
  # setting, not a bug in dockerTools.
  nonRootUser = "${toString imageLib.uid}:${toString imageLib.gid}";

  # A shell in a production image is the difference between "an attacker who reaches
  # code execution has a foothold" and "an attacker who reaches code execution has a
  # PHP process". Nixpkgs' PHP moves phpize and php-config — the only shell scripts it
  # ships — into the `dev` output, so the runtime closure should contain no shell at
  # all. Should. This is the check that says whether it does.
  forbiddenInRuntimeClosure = [
    "bash"
    "dash"
    "busybox"
    "zsh"
    "ksh"
    "toybox"
    "perl"
    "python3"
  ];
in
{
  # 1. Every image runs as uid 65532.
  image-runs-unprivileged =
    assert lib.assertMsg (imageLib.commonConfig.User == nonRootUser)
      "images/lib.nix must set config.User to a non-root uid:gid (ADR-0010 property 3)";
    assert lib.assertMsg (imageLib.uid != 0) "the image uid must not be 0";
    runCommand "victual-check-unprivileged" { } ''
      echo "image User = ${nonRootUser}" > "$out"
    '';

  # 2. No shell, no scripting runtime other than PHP, in what the serving images ship.
  image-has-no-shell =
    runCommand "victual-check-no-shell"
      {
        closure = closureInfo { rootPaths = [ php appRoot ]; };
      }
      ''
        found=""
        for forbidden in ${lib.escapeShellArgs forbiddenInRuntimeClosure}; do
          if grep -qE "^/nix/store/[a-z0-9]{32}-$forbidden(-[0-9]|\$)" "$closure/store-paths"; then
            found="$found $forbidden"
          fi
        done

        if [ -n "$found" ]; then
          echo "The app image's runtime closure contains:$found" >&2
          echo >&2
          echo "That means the image ships an interpreter the application never calls," >&2
          echo "which is exactly what docs/adr/0013-nix-built-container-images.md claims" >&2
          echo "it does not. Find the reference with:" >&2
          echo "  nix why-depends .#app <the offending store path>" >&2
          exit 1
        fi

        cp "$closure/store-paths" "$out"
      '';

  # 3. The document root the web tier serves contains no PHP. The web image has no
  #    interpreter, so a .php file there could only ever be served as source.
  webroot-has-no-php = runCommand "victual-check-webroot" { } ''
    if find ${webroot} -name '*.php' -print | grep -q .; then
      echo "PHP files in the web tier's document root:" >&2
      find ${webroot} -name '*.php' -print >&2
      exit 1
    fi
    echo "no .php under the document root" > "$out"
  '';

  # 4. The application tree assembled and the pieces the front controller needs are
  #    where it expects them. This is the smoke test for nix/approot.nix's copy.
  approot-is-complete = runCommand "victual-check-approot" { } ''
    for required in \
      app/public/index.php \
      app/app.php \
      app/config-dist.php \
      app/version.json \
      app/entrypoint.php \
      app/packages/autoload.php \
      app/views/layout/default.blade.php
    do
      if [ ! -e "${appRoot}/$required" ]; then
        echo "missing from the application root: $required" >&2
        exit 1
      fi
    done

    # The serving images must not carry the DDL corpus or the CLI entry points; the
    # migrate image is the only one that gets those.
    for forbidden in app/migrations app/db app/bin; do
      if [ -e "${appRoot}/$forbidden" ]; then
        echo "the serving application root should not contain $forbidden" >&2
        exit 1
      fi
    done

    echo ok > "$out"
  '';

  # 5. The image tag and the version the API reports are the same string. A deployment
  #    whose tag and /api/system/info disagree is one nobody can reason about.
  version-matches-the-application = runCommand "victual-check-version" { } ''
    reported=$(${jq}/bin/jq -r .Version ${appRoot}/app/version.json)
    if [ "$reported" != "${version}" ]; then
      echo "image tag ${version} does not match version.json ($reported)" >&2
      exit 1
    fi
    echo "${version}" > "$out"
  '';
}
