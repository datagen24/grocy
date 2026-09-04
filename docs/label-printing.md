Label printing
====

> **This document describes the path that exists today, and
> [ADR-0011](adr/0011-label-namespace.md) (accepted 2026-09-04) retires it.** Under that
> record, label creation enqueues a print job row and a drainer — its own workload, not
> this repository's process — renders, prints and retries; a reprint is resetting a row
> rather than re-triggering the booking that caused it. The webhook below and its
> `VICTUAL_LABEL_PRINTER_WEBHOOK` constant go with it, taking the tree's only outbound HTTP
> call. None of that is built or scheduled, so everything below is still how printing
> works — but no new work should extend the webhook, and the payload a label carries is
> `vctl:<uid>` from here forward rather than the `grcy:` code the request below shows.

To enable label printing, set `FEATURE_FLAG_LABEL_PRINTER` to `true`in your `config.php`. You also need to provide a webhook target that is responsible for printing.

Why webhook?
---

Label printers come in all shapes and forms, and your particular one is probably not the one used by the author of this feature. Also, Victual may does not have a
direct connection to a local label printer (e.g. Victual is hosted in a cloud vps). Thus, a lightweight implementation is provided by Victual: whenever something
should print, a POST request to a configured URL is made. The target then is responsible for label printing.

Reference implementation
---

The webhook was developed and tested against a Brother QL-600 label printer, using Brother DK-2205 endless 62mm label paper. The webhook provider implementation was
implemented into [a fork of brother_ql_web](https://github.com/mistressofjellyfish/brother_ql_web).

Webhook request
---

Requests can be configured to be sent server-side (that is, from the machine hosting Victual through GuzzleHttp) or by an AJAX request directly from the browser.
The latter is neccesary for situations where the Victual hosting machine cannot reach your label printer, however server-side requests are a bit faster and
tend to be more stable.

Both methods fire this request upon printing:

```
POST /your/printing/api/endpoint HTTP/1.1

product=<productname>&grocycode=grcy:x:xxx&due_date=DD:%2021-06-09&...

```

If specified, the request body may also be JSON encoded, however the fields stay the same.

Additional POST parameters (like the font to use) may be supplied in `config.php`. Keep in mind that these config values will be distributed to all clients on all requests
if the webhook is configured to run client-side.

The webhook receiver is required to layout and print the resulting label.
