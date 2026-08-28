# Development and CI image for this fork.
#
# This is not a production image. It exists so that `.devtools/pgsql/difftest.php` and
# `trigdifftest.php` — and the regression suite built on them — can run from a clean
# checkout with no host setup beyond Docker. Production packaging is a separate concern
# and deliberately not solved here.
#
# PHP 8.5 even though composer.json pins 8.4: the fork's floor is 8.4 (so an 8.4 box can
# still run it) while the shipped image stays current. See docs/plans/15-deliberate-cleanup.md,
# question 4.

FROM php:8.5-cli-bookworm

# libpq-dev for building pdo_pgsql, the image libraries for gd, ICU for intl, and libzip
# for zip. postgresql-client is separate and not optional: libpq-dev ships headers and the
# shared library, while run-tests.sh calls dropdb and createdb, which live in the client
# package. sqlite3 is the CLI, useful for poking at a failing seed by hand.
RUN apt-get update && apt-get install -y --no-install-recommends \
		git \
		unzip \
		sqlite3 \
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

# difftest.php and trigdifftest.php both default GROCY_ROOT to /app; being explicit means
# the scripts behave the same when invoked from somewhere else in the tree.
ENV GROCY_ROOT=/app

CMD ["php", "-v"]
