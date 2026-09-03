# What goes into an image, decided once.
#
# The Dockerfile does `COPY . /app` with no .dockerignore, so it ships .git, docs/, the
# differential test harness and whatever is lying around the working tree (plan 10 and
# sweep S25 both call this out). Nix inverts the default: nothing is in the source
# unless it is named here, so a new directory in the repository does not silently become
# part of the production image.
{ lib }:

let
  root = ../.;
  fs = lib.fileset;

  # Everything the running application reads. Note what is absent: docs/, .devtools/,
  # .github/, .agents/, changelog/, branding/, the Dockerfile and the compose file, and
  # the whole frontend toolchain — public/packages is built by nix/frontend.nix rather
  # than copied from a working tree that may not have run yarn.
  application = fs.unions [
    (root + "/app.php")
    (root + "/config-dist.php")
    (root + "/routes.php")
    (root + "/version.json")
    (root + "/composer.json")
    (root + "/composer.lock")
    # A runtime dependency, not a build artefact: BaseApiController::GetOpenApispec()
    # and UserfieldsService both read it on the request path to validate exposed
    # entities, permitted operations and file groups. Without it, every generic entity
    # request answers 500 rather than JSON.
    (root + "/victual.openapi.json")
    (fs.fileFilter (f: f.hasExt "php") (root + "/controllers"))
    (fs.fileFilter (f: f.hasExt "php") (root + "/helpers"))
    (fs.fileFilter (f: f.hasExt "php") (root + "/middleware"))
    (fs.fileFilter (f: f.hasExt "php") (root + "/services"))
    (root + "/views")
    (root + "/localization")
    (root + "/plugins")
    (root + "/bin")
    (root + "/migrations")
    (root + "/db")
    publicTree
  ];

  # public/ minus the things that are not assets: the .htaccess is Apache's rewrite
  # rule and this deployment is nginx, and public/packages is generated.
  publicTree = fs.difference (root + "/public") (fs.unions [
    (root + "/public/.htaccess")
  ]);

  # The CLI entry points. `bin/victual-migrate` and `bin/victual-db-import` are the
  # migrate image's whole reason for existing and nothing on the request path invokes
  # them, so nix/approot.nix drops them from the serving images.
  #
  # `migrations/` and `db/` are NOT in this list, and the first draft of this file was
  # wrong to put them here. The request path still reads both: `SystemController::Root`
  # calls `MigrateDatabase()`, whose `GetMigrationFiles()` opens a `FilesystemIterator`
  # over `migrations/` and throws when the directory is absent, and PostgreSQL's
  # baseline path resolves to `db/pgsql/baseline`. Stripping them turned `/` from a 302
  # into a 500. They come out when plan 10 takes migration off the request path and the
  # schema-version check can read metadata generated at build time instead of counting
  # files — not before.
  cliPaths = [
    "bin"
  ];
in
{
  inherit application cliPaths;

  # Composer resolves from these two files alone; keeping the vendor derivation's src
  # this narrow means editing a controller does not invalidate the dependency fetch.
  composerFiles = fs.unions [
    (root + "/composer.json")
    (root + "/composer.lock")
  ];

  yarnFiles = fs.unions [
    (root + "/package.json")
    (root + "/yarn.lock")
  ];

  toSource = fileset: fs.toSource { inherit root fileset; };
}
