# The application tree with its Composer dependencies resolved.
#
# `buildComposerProject2` splits this in two: a fixed-output derivation that fetches the
# dependency graph named by composer.lock (the only step allowed to touch the network,
# and the only one with a hash in nix/hashes.nix), and an ordinary derivation that
# assembles the tree. Two of the sixty-one locked packages are VCS forks —
# berrnd/lessql and berrnd/php-gettext — and both are pinned to a commit with a GitHub
# zipball dist in composer.lock, so the fetch is content-addressed like every other one.
#
# The result lands at $out/share/php/victual. nix/approot.nix is what puts it at /app.
{
  lib,
  php,
  hashes,
  sources,
  version,
}:

php.buildComposerProject2 (finalAttrs: {
  pname = "victual";
  inherit version;

  src = sources.toSource sources.application;

  vendorHash = hashes.composerVendor;

  # composer.json describes an application, not a library: it has no name, description
  # or license field, and `composer validate --strict` fails on all three. Validating it
  # would be checking a property this repository has never claimed to have.
  composerStrictValidation = false;

  # require-dev is phpunit/php-code-coverage, which exists for the differential suite's
  # coverage mode and has no business in a production image.
  composerNoDev = true;
  composerNoScripts = true;
  composerNoPlugins = true;

  meta = {
    description = "Victual application tree with Composer dependencies";
    homepage = "https://github.com/datagen24/victual";
    license = lib.licenses.mit;
    platforms = lib.platforms.all;
  };
})
