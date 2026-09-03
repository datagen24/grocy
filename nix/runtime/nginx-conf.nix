# The static tier's nginx configuration.
#
# It serves `${webroot}` — css, js, images, and the yarn-installed frontend packages —
# and hands everything else to php-fpm over loopback. It never reads a PHP file: the
# `.php` location matches before `try_files` gets a chance to stat anything, so the web
# image contains no application code at all. That is the whole reason the split exists.
#
# Every path nginx would otherwise want to write to is pointed at /tmp, which is a
# tmpfs/emptyDir in the deployment. With those set, the image's root filesystem can be
# mounted read-only.
{
  writeText,
  lib,
  nginx,
  webroot,
  appRootPath ? "/app",
  listenPort ? 8080,
  fpmPort ? 9000,
}:

writeText "victual-nginx.conf" ''
  # PID 1 is nginx itself; it must not fork away.
  daemon off;
  worker_processes auto;
  pid /tmp/nginx.pid;
  error_log /dev/stderr warn;

  events {
    worker_connections 1024;
  }

  http {
    include ${nginx}/conf/mime.types;
    default_type application/octet-stream;

    access_log /dev/stdout combined;

    # Everything nginx writes, in one place, so the root filesystem does not have to be
    # writable for any of it.
    client_body_temp_path /tmp/nginx/client-body;
    proxy_temp_path /tmp/nginx/proxy;
    fastcgi_temp_path /tmp/nginx/fastcgi;
    uwsgi_temp_path /tmp/nginx/uwsgi;
    scgi_temp_path /tmp/nginx/scgi;

    sendfile on;
    tcp_nopush on;
    keepalive_timeout 65;
    server_tokens off;

    # Matches php.ini's post_max_size. A mismatch here is the classic "the upload fails
    # with an HTML error page instead of a JSON one".
    client_max_body_size 32m;

    gzip on;
    gzip_types text/css text/javascript application/javascript application/json image/svg+xml;
    gzip_min_length 1024;

    server {
      listen ${toString listenPort};
      listen [::]:${toString listenPort};

      # The static tree. The application's own root is ${appRootPath} inside the *app*
      # container; this is only the assets half, and it is deliberately a different
      # store path.
      root ${webroot};
      index index.php;

      # TLS termination, HSTS and the rest belong to the ingress. What is set here is
      # what only the origin can know.
      add_header X-Content-Type-Options nosniff always;
      add_header X-Frame-Options SAMEORIGIN always;
      add_header Referrer-Policy no-referrer always;

      location = /robots.txt {
        access_log off;
      }

      # Frontend libraries and application assets are content-addressed by the ?v=
      # query the Blade layout appends, so they can be cached hard.
      location ~* ^/(packages|css|js|viewjs|img|uisounds)/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
      }

      location / {
        try_files $uri $uri/ /index.php$is_args$args;
      }

      # The only PHP that ever runs. SCRIPT_FILENAME names a path inside the *app*
      # container, which is why both images agree on ${appRootPath}: nginx does not open
      # this file, php-fpm does.
      location ~ ^/index\.php(/|$) {
        fastcgi_pass 127.0.0.1:${toString fpmPort};
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include ${nginx}/conf/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${appRootPath}/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param DOCUMENT_ROOT ${appRootPath}/public;
        fastcgi_read_timeout 60s;
        fastcgi_buffering on;
      }

      # Anything else ending in .php is not ours. Without this a future asset called
      # foo.php would be served as source.
      location ~ \.php$ {
        return 404;
      }

      # Dotfiles are not assets.
      location ~ /\. {
        deny all;
      }
    }
  }
''
