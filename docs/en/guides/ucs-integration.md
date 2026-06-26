# UCS Integration — Per-Line Wildcard Subdomains

This feature supports **UCS integration** by giving each client a unique load-balancer subdomain based on their **line ID** when stream redirects are built.

## Overview

When a load-balancer (LB) server uses a wildcard domain and **Serve Random IP / Domain** is enabled, XC_VM replaces the `wildcard.` prefix with the authenticated line ID before redirecting the client.

| Line ID | LB domain (configured) | Redirect domain |
|--------:|------------------------|-----------------|
| 10 | `wildcard.example.com` | `10.example.com` |
| 42 | `wildcard.lb1.com` | `42.lb1.com` |

Each line gets a stable subdomain. UCS (or upstream DNS/proxy) can route `*.example.com` to the correct backend or apply per-line policies.

## Requirements

1. **Load-balancer server** (not the main server) with at least one domain configured under **Domains and IPs**.
2. **Serve Random IP / Domain** enabled on that LB (`random_ip` checkbox on the server edit page).
3. A domain entry that starts with **`wildcard.`** (e.g. `wildcard.example.com`).
4. DNS: a wildcard record for the base zone (e.g. `*.example.com`) pointing at the LB or your UCS edge.

## Setup

1. Open **Servers → Edit** on the target LB server.
2. Under **Domains and IPs**, add `wildcard.example.com` (use your real zone).
3. Enable **Serve Random IP / Domain**.
4. Save the server.
5. Ensure DNS resolves `{line_id}.example.com` for any line ID you issue (wildcard DNS or UCS automation).

When a client authenticates via `auth.php`, XC_VM reads the line ID from `$rUserInfo['id']` and passes it into the redirect URL builder.

## When It Applies

The substitution runs only when **all** of the following are true:

- The request is authenticated and a line ID is available.
- **Serve Random IP / Domain** is enabled on the selected LB.
- The randomly chosen domain from the server’s domain list contains the substring `wildcard.`.

If the client connected using a matching `Host` header, the existing host is kept and wildcard substitution is skipped.

## Example Flow

1. LB server domains: `wildcard.example.com`, `lb2.example.com`
2. **Serve Random IP / Domain**: on
3. Client line ID: `10`
4. Auth picks `wildcard.example.com` from the domain list
5. Redirect URL host becomes `10.example.com`

## Implementation

Logic lives in `StreamRedirector::getStreamingURL()`:

```php
// line_id : 10 => wildcard.lb1.com => 10.lb1.com
if ($rUserID && strpos($rDomain, 'wildcard.') !== false) {
    $rDomain = str_replace('wildcard.', $rUserID . '.', $rDomain);
}
```

Called from `Public/stream/auth.php` with `$rUserID = $rUserInfo['id']` for live, VOD, timeshift, and related redirects.

## Notes

- Use the literal prefix **`wildcard.`** in the domain string; only that segment is replaced.
- The line ID is the internal **lines** table ID (`users.id` in streaming auth), not the username.
- Wildcard substitution applies to LB redirect URLs only; it does not change playlist or API hostnames unless those paths also call `getStreamingURL()` with a line ID.

## Related files

| File | Role |
| --- | --- |
| `src/Streaming/Delivery/StreamRedirector.php` | `getStreamingURL()` wildcard substitution |
| `src/Public/stream/auth.php` | Calls the redirect builder with the line ID |
