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
  app,
  appRoot,
  runtimeNginxConf,
  webroot,
  configSeed,
  runtime,
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
        closure = closureInfo { rootPaths = [ php app runtime.entrypoint ]; };
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

  # 4. Everything the request path opens by absolute or __DIR__-relative path is in the
  #    application root. This list is not decoration: every entry is a file some part of
  #    the tree reads at runtime, and three of them were missing from the first version
  #    of nix/source.nix.
  #
  #    victual.openapi.json — BaseApiController::GetOpenApispec() and UserfieldsService.
  #      Absent, every generic entity request answers 500.
  #    migrations/, db/ — DatabaseMigrationService::GetMigrationFiles() opens a
  #      FilesystemIterator that throws on a missing directory, and
  #      PostgresDialect::GetBaselineSchemaPath resolves to db/pgsql/baseline. Since plan
  #      10, SchemaVersionMiddleware enumerates the corpus on every request.
  #    viewcache/ — baked by nix/app.nix so the directory can be read-only.
  app-is-complete = runCommand "victual-check-app" { } ''
    for required in \
      public/index.php \
      app.php \
      config-dist.php \
      version.json \
      victual.openapi.json \
      packages/autoload.php \
      views/layout/default.blade.php \
      migrations \
      db/pgsql/baseline \
      bin/victual-migrate \
      viewcache
    do
      if [ ! -e "${appRoot}/$required" ]; then
        echo "missing from the application root: $required" >&2
        echo "see nix/source.nix's allowlist" >&2
        exit 1
      fi
    done

    echo ok > "$out"
  '';

  # 5. The view cache was actually warmed, and warmed for the path it will be served
  #    from. Blade names a compiled file after a hash of the absolute path of its source
  #    directory, so a cache warmed elsewhere is a 500 on every page against a read-only
  #    directory — the warmer's own comment calls this load-bearing. Baking it inside the
  #    application derivation is what makes the two paths the same by construction; this
  #    check is what notices if that ever stops being true.
  viewcache-is-warm = runCommand "victual-check-viewcache" { } ''
    # Counted rather than hardcoded. A partial is compiled separately from the page that
    # includes it, so the number is "every .blade.php under views/", and it moves whenever
    # anyone adds or removes one — plan 12 changed it from 96 to 97 while this branch was
    # open. A literal here would have been a check that silently stopped meaning anything.
    templates=$(find ${appRoot}/views -name '*.blade.php' | wc -l)
    compiled=$(find ${appRoot}/viewcache -name '*.php' -not -name 'route_cache*' | wc -l)
    echo "templates: $templates, compiled: $compiled"

    if [ "$compiled" -lt "$templates" ]; then
      echo "the baked view cache has $compiled compiled templates for $templates sources" >&2
      echo "a template the warmer missed is a 500 the first time somebody opens that page" >&2
      exit 1
    fi

    if ! find ${appRoot}/viewcache -name 'route_cache*.php' | grep -q .; then
      echo "no route cache in the baked directory" >&2
      exit 1
    fi

    echo "$compiled" > "$out"
  '';

  # 6. The entrypoint's seed path exists as an image layer. Declaring the file in the
  #    overlay and never putting it in an image is what the first version of this tree
  #    did, and it made the migrate initContainer exit 1 on a fresh data directory —
  #    which, being an initContainer, kept the whole pod from starting.
  config-seed-is-installed = runCommand "victual-check-config-seed" { } ''
    seeded=${configSeed}/etc/victual/config.php
    if [ ! -f "$seeded" ]; then
      echo "no config.php at /etc/victual/config.php in the seed layer" >&2
      exit 1
    fi

    # The path is written down in two places and they have to agree.
    if ! grep -q "'/etc/victual/config.php'" ${runtime.entrypoint}; then
      echo "nix/runtime/entrypoint.php no longer seeds from /etc/victual/config.php" >&2
      echo "nix/config-seed.nix installs it there; one of the two has moved" >&2
      exit 1
    fi

    echo ok > "$out"
  '';

  # 7. The web tier does not drag the application in behind it.
  #
  #    nginx has to name the file php-fpm should execute, and that name is a store path in
  #    the *other* container. Interpolating it normally would make the application a
  #    dependency of the nginx configuration and therefore of the web image — every PHP
  #    file, in the tier whose whole purpose is not to have any. nix/runtime/nginx-conf.nix
  #    discards the string context to keep the text and drop the edge, and that is exactly
  #    the sort of thing that regresses the next time somebody edits the file.
  web-tier-carries-no-application =
    runCommand "victual-check-web-closure"
      {
        closure = closureInfo { rootPaths = [ runtimeNginxConf webroot ]; };
      }
      ''
        if grep -qF "${app}" "$closure/store-paths"; then
          echo "the web tier's closure contains the application:" >&2
          echo "  ${app}" >&2
          echo >&2
          echo "SCRIPT_FILENAME in nix/runtime/nginx-conf.nix has to be wrapped in" >&2
          echo "builtins.unsafeDiscardStringContext, or the web image ships every PHP" >&2
          echo "file it exists in order not to have." >&2
          exit 1
        fi

        cp "$closure/store-paths" "$out"
      '';

  # 8. The image tag and the version the API reports are the same string. A deployment
  #    whose tag and /api/system/info disagree is one nobody can reason about.
  version-matches-the-application = runCommand "victual-check-version" { } ''
    reported=$(${jq}/bin/jq -r .Version ${appRoot}/version.json)
    if [ "$reported" != "${version}" ]; then
      echo "image tag ${version} does not match version.json ($reported)" >&2
      exit 1
    fi
    echo "${version}" > "$out"
  '';
}
