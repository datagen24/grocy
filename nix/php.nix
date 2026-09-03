# The PHP the images run.
#
# `buildEnv`'s `extensions` argument *replaces* nixpkgs' default extension set rather
# than adding to it, which is the point: the stock build enables a couple of dozen
# extensions, and every one of them is code in the address space of a process that holds
# the database owner's credentials. The list below is derived from what the tree
# actually calls — helpers/PrerequisiteChecker.php names ten of them, and the rest carry
# their caller in the comment beside them.
#
# Trimming further is a measurement, not a guess: boot the image, walk the UI and the
# API, and remove what nothing loaded. Plan 20 carries that as a verification step
# because it cannot be done by reading.
{
  lib,
  php85,

  # `PrerequisiteChecker::checkDatabaseRequirements()` became driver-aware when plan 10
  # landed, so a PostgreSQL deployment no longer loads pdo_sqlite on every request just
  # to satisfy a check. Only the migrate image needs it, for bin/victual-db-import, which
  # ADR-0008 keeps SQLite around for.
  withSqlite ? false,
}:

php85.buildEnv {
  extensions =
    { all, ... }:
    with all;
    [
      # --- PrerequisiteChecker::REQUIRED_PHP_EXTENSIONS -------------------------------
      ctype
      fileinfo
      filter
      gd # also gumlet/php-image-resize, for userpicture handling
      iconv
      intl
      mbstring
      tokenizer
      zlib
      # json is compiled in unconditionally since PHP 8.0 and has no extension attribute.
      # session is *not* here: nothing in the fork's own tree calls session_start(), and
      # the point of this list is that an extension needs a caller. If something under
      # packages/ turns out to want it, that is a plan 20 Q4 finding and this is where
      # the line goes back.

      # --- What the deployment actually talks to --------------------------------------
      pdo
      pdo_pgsql

      # --- Traced to a caller ---------------------------------------------------------
      curl # guzzlehttp/guzzle, and through it the barcode-lookup plugins
      dom # ezyang/htmlpurifier, gettext/gettext's non-.mo loaders
      simplexml # ditto
      xmlwriter # ditto
      zip # mike42/escpos-php, gettext/gettext
      openssl # TLS for guzzle, and for libpq when DB_SSLMODE asks for it
      opcache # what makes an immutable image fast; see the ini
      pcntl # nix/runtime/entrypoint.php execs php-fpm without a shell in the image
    ]
    ++ lib.optional withSqlite pdo_sqlite;

  # Appended to the generated extension-loading ini, so it applies to php-fpm and to the
  # CLI alike — no `-c` flag to remember, and no image whose PHP behaves differently
  # depending on how it was invoked.
  extraConfig = import ./runtime/php-ini.nix;
}
