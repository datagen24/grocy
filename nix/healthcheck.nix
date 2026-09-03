# The app container's liveness probe, at a fixed path.
#
# php-fpm speaks FastCGI rather than HTTP and binds loopback only, and the kubelet
# resolves a `tcpSocket` probe's target to the *pod IP* — so a TCP probe fails against a
# perfectly healthy pool and restarts it, and setting the probe's `host` to `127.0.0.1`
# names the node's loopback instead. The probe has to run inside the container, which
# means the manifest has to be able to name it.
#
# A store path is not a name a manifest can carry, so this places the script at
# /opt/victual/healthcheck with the interpreter in its shebang. The kernel handles
# shebangs; no shell is involved, and `/bin` stays empty. It is a predictable path to a
# PHP interpreter, which is worth one sentence: the container already runs PHP, so this is
# not a capability an attacker inside it did not have.
{
  runCommand,
  php,
  runtime,
  version,
}:

runCommand "victual-healthcheck-${version}"
  {
    meta.description = "In-container liveness probe for Victual's php-fpm pool";
  }
  ''
    mkdir -p "$out/opt/victual"

    # Replace the portable shebang with this image's interpreter.
    {
      echo '#!${php}/bin/php'
      tail -n +2 ${runtime.healthcheck}
    } > "$out/opt/victual/healthcheck"

    chmod +x "$out/opt/victual/healthcheck"
  ''
