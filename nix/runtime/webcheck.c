/*
 * Liveness/readiness probe for the nginx container. Exits 0 when a GET of the given
 * path answers 2xx or 3xx, 1 otherwise.
 *
 * Why this exists rather than the `httpGet` probe the manifest used to carry.
 *
 * In Kubernetes, `httpGet` is performed by the kubelet from outside the container, and
 * it is the right shape for a tier that speaks HTTP: nothing has to exist inside the
 * image for it to work. `podman kube play` implements the same field differently — it
 * translates it into a container healthcheck, `CMD-SHELL curl -f <url> || exit 1`, and
 * runs it *inside* the container. This image has no shell and no curl, by design, so
 * the probe could never pass; the startupProbe failed thirty times and podman killed a
 * container that was serving requests correctly throughout. That is issue #49's second
 * half, and it is the same shape as its first: a manifest written for Kubernetes,
 * silently meaning something else under podman.
 *
 * An `exec` probe means the same thing to both runtimes, so the manifest names this and
 * both agree. The cost is a process per probe where the kubelet would have opened a
 * socket itself, which for a check every 2 to 10 seconds is not a cost worth a second
 * manifest.
 *
 * Why C, in a tree that is otherwise PHP. The app container's probe is a PHP script
 * with the interpreter in its shebang, which is free there because that container
 * already runs PHP. This one must not be: the whole argument for the web tier is that
 * it has no interpreter, so a traversal bug can leak a stylesheet and nothing else
 * (nix/checks.nix asserts the document root holds no .php, and now that the closure
 * holds no interpreter either). Statically linked, this adds a binary with an empty
 * runtime closure — the only shape that adds a capability without adding a language.
 *
 * Usage:  webcheck /robots.txt        (port from VICTUAL_WEB_PORT, default 8080)
 */

#include <arpa/inet.h>
#include <netinet/in.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <unistd.h>

#define DEFAULT_PORT 8080
#define TIMEOUT_SECONDS 2

int main(int argc, char **argv)
{
	const char *path = (argc > 1) ? argv[1] : "/robots.txt";

	if (path[0] != '/')
	{
		fprintf(stderr, "webcheck: path must start with '/', got \"%s\"\n", path);
		return 1;
	}

	int port = DEFAULT_PORT;
	const char *port_env = getenv("VICTUAL_WEB_PORT");

	if (port_env != NULL && port_env[0] != '\0')
	{
		char *end = NULL;
		long parsed = strtol(port_env, &end, 10);

		if (end == port_env || *end != '\0' || parsed < 1 || parsed > 65535)
		{
			fprintf(stderr, "webcheck: VICTUAL_WEB_PORT is not a port: \"%s\"\n", port_env);
			return 1;
		}

		port = (int) parsed;
	}

	int fd = socket(AF_INET, SOCK_STREAM, 0);

	if (fd < 0)
	{
		perror("webcheck: socket");
		return 1;
	}

	/* Every phase gets its own deadline, so a half-open connection cannot hang the
	 * probe past the timeout the manifest sets on it. */
	struct timeval timeout = { .tv_sec = TIMEOUT_SECONDS, .tv_usec = 0 };
	setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &timeout, sizeof(timeout));
	setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));

	struct sockaddr_in address;
	memset(&address, 0, sizeof(address));
	address.sin_family = AF_INET;
	address.sin_port = htons((unsigned short) port);
	address.sin_addr.s_addr = htonl(INADDR_LOOPBACK);

	if (connect(fd, (struct sockaddr *) &address, sizeof(address)) != 0)
	{
		fprintf(stderr, "webcheck: nginx is not accepting connections on 127.0.0.1:%d\n", port);
		close(fd);
		return 1;
	}

	/* HTTP/1.0 with an explicit close: no keep-alive to shut down, no chunked body to
	 * parse, and the status line is all this reads. */
	char request[1024];
	int written = snprintf(request, sizeof(request),
		"GET %s HTTP/1.0\r\nHost: localhost\r\nUser-Agent: victual-webcheck\r\nConnection: close\r\n\r\n",
		path);

	if (written < 0 || (size_t) written >= sizeof(request))
	{
		fprintf(stderr, "webcheck: path is too long\n");
		close(fd);
		return 1;
	}

	ssize_t sent = write(fd, request, (size_t) written);

	if (sent != (ssize_t) written)
	{
		fprintf(stderr, "webcheck: could not send the request\n");
		close(fd);
		return 1;
	}

	/* Read until the status line is complete rather than trusting one read() to deliver
	 * it. A short read is legal on a socket and would otherwise be parsed as a truncated
	 * status — "HTTP/1.1 2" scans as 2, which is not 200 and would fail a healthy tier
	 * intermittently. Enough for the status line and then some; the rest of the response
	 * is discarded unread, which the close below tells nginx about. */
	char response[256];
	size_t filled = 0;

	while (filled < sizeof(response) - 1)
	{
		ssize_t received = read(fd, response + filled, sizeof(response) - 1 - filled);

		if (received < 0)
		{
			fprintf(stderr, "webcheck: read failed before a status line arrived\n");
			close(fd);
			return 1;
		}

		if (received == 0)
		{
			break;
		}

		filled += (size_t) received;
		response[filled] = '\0';

		if (memchr(response, '\n', filled) != NULL)
		{
			break;
		}
	}

	close(fd);

	if (filled == 0)
	{
		fprintf(stderr, "webcheck: no response from nginx\n");
		return 1;
	}

	response[filled] = '\0';

	if (memchr(response, '\n', filled) == NULL)
	{
		fprintf(stderr, "webcheck: no complete status line in the response\n");
		return 1;
	}

	int status = 0;

	if (sscanf(response, "HTTP/%*d.%*d %3d", &status) != 1)
	{
		fprintf(stderr, "webcheck: not an HTTP response\n");
		return 1;
	}

	if (status < 200 || status >= 400)
	{
		fprintf(stderr, "webcheck: GET %s answered %d\n", path, status);
		return 1;
	}

	return 0;
}
