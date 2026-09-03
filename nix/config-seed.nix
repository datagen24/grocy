# /etc/victual/config.php — the near-empty config file the entrypoint seeds into
# VICTUAL_DATAPATH on start.
#
# It is its own derivation because it does not belong to the application: it lands at
# /etc, it exists only to satisfy
# `PrerequisiteChecker`'s "config.php must be in the data directory" rule, and it is
# deleted alongside nix/runtime/entrypoint.php when plan 10 lands.
#
# Both PHP images list it in `contents`. The first version of this tree declared it in
# the overlay and then never put it into an image, which made the migrate initContainer
# exit 1 with "no config.php … and none to seed from at /etc/victual/config.php" on a
# fresh emptyDir — and, because it is an initContainer, kept the serving containers from
# ever starting. nix/checks.nix now asserts the path exists.
{
  runCommand,
  runtime,
  version,
}:

runCommand "victual-config-seed-${version}"
  {
    meta.description = "The config.php stub Victual's entrypoint seeds into the data directory";
  }
  ''
    install -Dm444 ${runtime.configPhp} "$out/etc/victual/config.php"
  ''
