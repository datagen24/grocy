#!/bin/bash

# Upstream grocy's release-based updater, kept as inherited and deliberately NOT renamed
# with the rest of the project (plan 16): it overwrites the installation with a release
# downloaded from upstream's server, which is not this fork's code. This fork tracks no
# release schedule and updates by pulling the repository. See README.md, "How to update".

GROCY_RELEASE_URL=https://releases.grocy.info/latest


echo Start updating Grocy

set -e
shopt -s extglob
pushd `dirname $0` > /dev/null

backupBundleFileName="backup_`date +%Y-%m-%d_%H-%M-%S`.tgz"
echo Making a backup of the current installation in ./data/backups/$backupBundleFileName
mkdir -p ./data/backups > /dev/null
touch ./data/backups/$backupBundleFileName
tar -zcvf ./data/backups/$backupBundleFileName --exclude ./data/backups . > /dev/null
find ./data/backups/*.tgz -mtime +60 -type f -delete

echo Deleting everything except ./data and this script
rm -rf !(data|update.sh) > /dev/null

echo Downloading latest release
rm -f ./grocy-latest.zip > /dev/null
wget $GROCY_RELEASE_URL -q --show-progress -O ./grocy-latest.zip > /dev/null

echo Unzipping latest release
unzip -o ./grocy-latest.zip > /dev/null
rm -f ./grocy-latest.zip > /dev/null

echo Warming the view cache
# The compiled templates and the route cache are derived from the source tree that was
# just replaced, and nothing empties or rebuilds them on the next request any more - the
# version hash redirect that used to do it is gone (docs/plans/10-cold-start-statelessness.md).
# Failing here is not fatal to the update itself, so it is reported rather than fatal:
# the previous cache is still valid for everything that did not change.
php ./bin/victual-warm-cache || echo "WARNING: warming the view cache failed - run php bin/victual-warm-cache by hand"

popd > /dev/null

echo Finished updating Grocy
