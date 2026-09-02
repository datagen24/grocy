# Two images from one file.
#
#   docker build --target dev .          the development and CI image (the one that existed first)
#   docker build .                       the production image, which is the default target
#
# They are deliberately different things. The dev image exists so that
# `.devtools/pgsql/difftest.php`, `trigdifftest.php` and the regression suite built on
# them run from a clean checkout with no host setup beyond Docker: it carries a PHP CLI, a
# PostgreSQL client, pcov, and the working tree mounted over it. The production image
# serves HTTP, runs as a non-root user, writes nothing outside the data directory, and
# carries a view cache baked at build time - see docs/plans/10-cold-start-statelessness.md
# and sweep finding S25.
#
# PHP 8.5 even though composer.json pins 8.4: the fork's floor is 8.4 (so an 8.4 box can
# still run it) while the shipped image stays current. See docs/plans/15-deliberate-cleanup.md,
# question 4.


# ---------------------------------------------------------------------------------------
# Development and CI
# ---------------------------------------------------------------------------------------
FROM php:8.5-cli-bookworm AS dev

# libpq-dev for building pdo_pgsql, the image libraries for gd, ICU for intl, and libzip
# for zip. postgresql-client is separate and not optional: libpq-dev ships headers and the
# shared library, while run-tests.sh calls dropdb and createdb, which live in the client
# package. sqlite3 is the CLI, useful for poking at a failing seed by hand, and libsqlite3-dev
# is what docker-php-ext-install needs to build pdo_sqlite: the base image ships the headers
# for neither stage, and the first CI build of this file failed on exactly that.
RUN apt-get update && apt-get install -y --no-install-recommends \
		git \
		unzip \
		sqlite3 \
		libsqlite3-dev \
		libpq-dev \
		postgresql-client \
		libicu-dev \
		libzip-dev \
		libpng-dev \
		libjpeg62-turbo-dev \
		libfreetype6-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j"$(nproc)" \
		pdo_sqlite \
		pdo_pgsql \
		gd \
		intl \
		zip \
	&& rm -rf /var/lib/apt/lists/*

# pcov, so `SUITE_COVERAGE=1 .devtools/pgsql/run-tests.sh` works in the image without
# further setup. It is the cheap driver — line coverage only, no stepping or profiling —
# and it does nothing at all unless a process asks it to, so leaving it enabled costs the
# ordinary runs nothing measurable. Xdebug would also work and is roughly an order of
# magnitude slower.
RUN pecl install pcov \
	&& docker-php-ext-enable pcov

# fileinfo, ctype, zlib, mbstring, filter, iconv, tokenizer and json are already compiled
# into the base image; PrerequisiteChecker checks for all of them.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so editing source does not invalidate the vendor layer.
# --ignore-platform-req=php keeps the image usable while composer.json still pins 8.5.*;
# once 15-C7 lowers that pin to 8.4 the flag becomes a no-op and can be dropped.
COPY composer.json composer.lock ./
RUN composer install \
		--no-interaction \
		--no-progress \
		--no-scripts \
		--ignore-platform-req=php

COPY . /app

# difftest.php and trigdifftest.php both default VICTUAL_ROOT to /app; being explicit means
# the scripts behave the same when invoked from somewhere else in the tree.
ENV VICTUAL_ROOT=/app

CMD ["php", "-v"]


# ---------------------------------------------------------------------------------------
# Front end packages
# ---------------------------------------------------------------------------------------
# The views load CSS and JS from /packages/..., which yarn installs into public/packages
# (see .yarnrc). Built in its own stage so that node is not in the shipped image. The
# full image rather than the slim one because yarn.lock pins at least one package to a
# git repository, and yarn shells out to git to fetch it - the slim image has none and
# the first CI build of this stage failed on exactly that.
FROM node:22-bookworm AS assets

WORKDIR /app
COPY package.json yarn.lock .yarnrc ./
RUN yarn install --frozen-lockfile


# ---------------------------------------------------------------------------------------
# Production
# ---------------------------------------------------------------------------------------
# Apache with mod_php rather than php-fpm: this image serves HTTP by itself, which is one
# container rather than two and one fewer thing to get wrong for a household-sized
# deployment. It listens on 8080 because it runs as www-data, and a non-root process
# cannot bind 80.
FROM php:8.5-apache-bookworm AS production

# pdo_sqlite is installed even though ADR-0008 makes PostgreSQL the only runtime engine:
# bin/victual-db-import reads SQLite as an import format, which is the one thing that
# record keeps SQLite for. DB_DRIVER still refuses to be anything but pgsql here.
# git is a build-time dependency of composer rather than of the application: two packages
# in composer.json come from forks on GitHub, and when their dist archives cannot be
# fetched composer falls back to cloning them, which needs git. It is purged again below,
# together with composer itself.
RUN apt-get update && apt-get install -y --no-install-recommends \
		git \
		libsqlite3-dev \
		libpq-dev \
		libicu-dev \
		libzip-dev \
		libpng-dev \
		libjpeg62-turbo-dev \
		libfreetype6-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j"$(nproc)" \
		pdo_sqlite \
		pdo_pgsql \
		gd \
		intl \
		zip \
	&& rm -rf /var/lib/apt/lists/*

# The upstream production ini: display_errors off, and the other defaults that differ from
# the development one.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Where PHP puts temporary files, said out loud rather than left to the default.
#
# This image is meant to run with a read-only root filesystem, and PHP needs a writable
# temporary directory more often than the code suggests. Three callers, all real:
#
#   - gumlet/php-image-resize's getImageAsString() writes the re-encoded image to
#     tempnam(sys_get_temp_dir(), '') and reads it back, so every first request for a
#     thumbnail (FilesService::GetDownscaledFileName) needs one.
#   - DatabaseStorage streams a file through php://temp/maxmemory:2097152, which spills
#     to the temporary directory for anything over 2 MiB.
#   - PHP's own multipart upload handling, which is upload_tmp_dir.
#
# sys_temp_dir and upload_tmp_dir are set as well as TMPDIR because sys_get_temp_dir()
# consults the ini setting first and the environment only after it, and because a value
# an operator can read in phpinfo() is worth more than one they have to infer.
#
# The two size directives are here because php.ini-production sets upload_max_filesize to
# 2M, and Victual takes the smallest of that, post_max_size and FILE_STORAGE_MAX_SIZE_MB
# as its effective upload limit (services/Storage/FileSizeLimit.php, plan 01 Q2). Left
# alone, this image would quietly cap every upload at 2 MiB no matter what the household
# configured. 8M matches php.ini-production's own post_max_size, which is the number it
# would have had if the two directives agreed.
ENV TMPDIR=/tmp
RUN { \
		echo 'sys_temp_dir = /tmp'; \
		echo 'upload_tmp_dir = /tmp'; \
		echo 'upload_max_filesize = 8M'; \
		echo 'post_max_size = 8M'; \
	} > "$PHP_INI_DIR/conf.d/victual.ini"

# Apache: serve /app/public on 8080, let .htaccess do the URL rewriting, and log to the
# container's stdout/stderr rather than to files.
#
# **The complete list of paths this image needs writable, and what writes to each:**
#
#   /data              the application's own data directory - config.php is read, but
#                      FILE_STORAGE=filesystem writes uploads under it, and a SQLite
#                      database (dev, or bin/victual-db-import) lives there
#   /var/run/apache2   Apache's pid file, which apache2-foreground removes and apache2
#                      then writes
#   /tmp               PHP's temporary directory - see the ini above for the three things
#                      that use it - and Apache's lock directory, below
#
# Nothing else. Nothing under /app is ever written: the view cache is baked below, and
# verification 4 of docs/plans/10-cold-start-statelessness.md is what checks that claim
# rather than repeating it.
#
# Two Debian details that decide the list. Debian's apache2.conf declares
# "Mutex file:${APACHE_LOCK_DIR} default", so Apache writes its mutex files into
# APACHE_LOCK_DIR (/var/lock/apache2 by default), which a read-only root filesystem
# refuses. The official php:apache image rewrites /etc/apache2/envvars so that every
# APACHE_* variable is a default the environment may override (": ${VAR:=value}" rather
# than "export VAR=value"), which is why the ENV below is enough and why the first version
# of this file, which tried to sed the export line, failed its own guard in CI: there is
# no such line. Pointing the lock directory at /tmp, which always exists and is always
# writable, keeps the list at three paths instead of four.
#
# APACHE_LOG_DIR stays /var/log/apache2 and is never written, because the logs go to the
# container's stdout and stderr instead - set globally as well as per vhost, since the
# main server opens its error log before any vhost applies, and other-vhosts-access-log is
# disabled because it would write a file this image has no use for.
ENV APACHE_LOCK_DIR=/tmp
RUN set -eux; \
	a2enmod rewrite; \
	a2dissite 000-default; \
	a2disconf other-vhosts-access-log || true; \
	sed -ri 's!^Listen 80$!Listen 8080!' /etc/apache2/ports.conf; \
	{ \
		echo 'ServerName victual'; \
		echo 'ServerTokens Prod'; \
		echo 'ServerSignature Off'; \
		echo 'TraceEnable Off'; \
		echo 'ErrorLog /proc/self/fd/2'; \
		echo '<VirtualHost *:8080>'; \
		echo '    DocumentRoot /app/public'; \
		echo '    ErrorLog /proc/self/fd/2'; \
		echo '    CustomLog /proc/self/fd/1 combined'; \
		echo '    <Directory /app/public>'; \
		echo '        Options -Indexes +FollowSymLinks'; \
		echo '        AllowOverride All'; \
		echo '        Require all granted'; \
		echo '    </Directory>'; \
		echo '</VirtualHost>'; \
	} > /etc/apache2/sites-available/victual.conf; \
	a2ensite victual; \
	mkdir -p /var/run/apache2; \
	chown -R www-data:www-data /var/run/apache2

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --no-dev: the only development dependency is the coverage driver the suite uses, and the
# suite does not run here. Composer itself is removed afterwards - a production image with
# a package manager in it is a production image someone will install something into.
COPY composer.json composer.lock ./
RUN composer install \
		--no-interaction \
		--no-progress \
		--no-scripts \
		--no-dev \
		--ignore-platform-req=php \
	&& rm -f /usr/bin/composer \
	&& apt-get purge -y --auto-remove git \
	&& rm -rf /var/lib/apt/lists/*

COPY . /app
COPY --from=assets /app/public/packages /app/public/packages

# The data directory is a mount, not part of the image: it holds config.php (a ConfigMap
# or a bind mount) and, when FILE_STORAGE is "filesystem", uploaded files. Nothing is baked
# into it, and in particular no credentials are - the database connection arrives as
# VICTUAL_DB_* environment variables or as settingoverrides files, per S25.
#
# The empty directory is created here so that it is a mount point the image declares rather
# than one the container runtime invents, and so that www-data can write it when what gets
# mounted is a bare tmpfs.
ENV VICTUAL_DATAPATH=/data
RUN mkdir -p /data && chown www-data:www-data /data

# The cache lives in the image rather than in the data directory, which is the whole point:
# it is derived from the source tree, so it is a layer. Baked here, owned by root, and the
# process below runs as www-data, so "read-only" is a file permission rather than a promise.
ENV VICTUAL_VIEWCACHE_PATH=/app/viewcache

# The route cache is compiled with the base path baked in, because Slim prefixes it onto
# every pattern before FastRoute sees them. An installation served under a subdirectory
# therefore has to build with it:
#
#   docker build --build-arg VICTUAL_BASE_PATH=/victual .
#
# Getting it wrong is loud rather than subtle - Slim refuses to start against a cache file
# it cannot find in a directory it cannot write.
ARG VICTUAL_BASE_PATH=""
ENV VICTUAL_BASE_PATH=${VICTUAL_BASE_PATH}

RUN php bin/victual-warm-cache

# Non-root, and the tree is not writable by the user that serves it (S25).
USER www-data

EXPOSE 8080

# Migrations are not run here. `php bin/victual-migrate` in an initContainer, or once by
# hand, is what brings a database up to date; an application whose schema does not match
# refuses to serve and says so.
CMD ["apache2-foreground"]
