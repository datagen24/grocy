<?php

/*
 * The config.php the images seed into VICTUAL_DATAPATH.
 *
 * It is empty on purpose. config-dist.php's Setting() resolves every setting in this
 * order — a file in VICTUAL_DATAPATH/settingoverrides, then a VICTUAL_-prefixed
 * environment variable, then the shipped default — so a container is configured with
 * environment variables and needs nothing here. What this file is for is
 * helpers/PrerequisiteChecker.php, which refuses to start when it is absent.
 *
 * Two things belong in a deployment rather than in this file, and neither is a setting:
 *
 *   VICTUAL_DB_PASSWORD  a Secret, mounted as an environment variable or as
 *                        settingoverrides/DB_PASSWORD.txt
 *   VICTUAL_BASE_URL     whatever the ingress publishes
 *
 * See deploy/README.md for the full list of what a running instance needs set.
 */
