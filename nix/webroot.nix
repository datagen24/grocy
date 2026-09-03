# The static tree the web tier serves.
#
# It is public/ with index.php removed and the yarn-built frontend packages grafted in
# at /packages, which is where the Blade layout looks for them. Removing index.php is
# not tidiness: the web image has no PHP interpreter, so a PHP file in its document root
# could only ever be served as source, and the surest way for that never to happen is
# for the file not to be there.
#
# Sourced from the application derivation rather than from the working tree, so the
# assets the web tier serves and the templates the app tier renders cannot drift apart.
{
  lib,
  runCommand,
  app,
  frontend,
  version,
}:

runCommand "victual-webroot-${version}"
  {
    meta.description = "Static document root served by Victual's web tier";
  }
  ''
    mkdir -p "$out"
    cp -R --no-preserve=mode ${app}/share/php/victual/public/. "$out"/
    rm -f "$out/index.php"

    cp -R --no-preserve=mode ${frontend}/packages "$out/packages"

    # Nothing in the serving path ever writes here.
    chmod -R a-w,a+rX "$out"
  ''
