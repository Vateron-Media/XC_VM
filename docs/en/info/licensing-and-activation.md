# Licensing & Activation

XC_VM is distributed under the **GNU Affero General Public License v3.0
(AGPL-3.0)**. This page explains how licensing works: what is checked, when, and
what data leaves your server.

## In short

For features that require a license, **two conditions must be met at the same
time**:

1. the attribution must be preserved in the panel;
2. a valid **activation key** must be installed on the server.

Neither condition replaces the other. A valid key does not allow you to remove
the attribution, and keeping the attribution does not replace the activation key.

A single-server panel works **without a key**: the admin, reseller and player
panels and streaming all run. See [What the license provides](#what-the-license-provides).

## Attribution

Attribution is a reference to the XC_VM project and Vateron Media, with links to
the repository and the AGPL license text. It is displayed:

- in the footer of the administrator panel;
- in the footer of the reseller panel;
- in the player footer;
- on the administrator and reseller login pages.

The panel automatically checks that the attribution is present **and actually
displayed**. If it is removed, commented out, or its output is disabled, the
license is no longer considered valid, even if the activation key itself is valid.

## Activation key

- The key is issued for **one specific panel installation**. It is not accepted
  on another server or by another installation.
- The key is signed by the license server. Changing even a single character makes
  it invalid.
- The key can be revoked on the license server, for example when the applicable
  terms are violated.

## How verification works

**When you enter the key.** The panel contacts the license server immediately. If
the key is invalid (revoked, issued for a different installation, or corrupted),
you find out right away.

**During operation.** Once a day the panel checks the key with the license
server and stores the result locally. The license server is not contacted on
every request, so verification does not affect the panel's performance.

**Daily check-in.** Separately, the main server sends one check-in a day. The
license server records which IP address the installation runs from, as evidence
against a leaked or cloned copy. Load balancers do not check in.

A panel **without a key never contacts the license server**.

**If the license server is unavailable.** Connectivity problems do not interrupt
operation:

- after you enter the key, the panel must successfully reach the license server
  **at least once, within 14 days of the key being issued**. This normally happens
  immediately, when the key is entered;
- once that has happened, the license keeps working offline **with no time
  limit**.

Only two things end a confirmed license: the key's own expiry date, or an explicit
revocation received from the license server.

## What data is transmitted

During verification the panel sends only the technical information the licensing
process needs:

- the key identifier;
- the installation identifier;
- a technical fingerprint of the environment: a SHA-256 hash of the installation
  identifier, `/etc/machine-id` and the hostname. The original values cannot be
  read back from it, but the same server always produces the same hash;
- a random one-time value (nonce) that protects against replayed responses.

The license server also sees the IP address the request comes from; the daily
check-in stores it.

Databases, user lists, channels, logs and other content are **not transmitted**.
The server's response is digitally signed, so it cannot be forged or tampered
with.

## What the license provides

The license is required for:

- **multi-server operation**: connecting additional servers and load balancers
  (LBs) to the main server;
- **the fanout delivery daemon**: without a license, live streams fall back to the
  legacy delivery path automatically. Nothing is blocked and no data is touched.

Everything else in a single-server panel works without a license.

## If the license expires or is revoked

- The main server **disconnects additional servers (LBs) from the database**, and
  new servers cannot be connected.
- The main server itself keeps working.
- Once the key is renewed or replaced, additional servers can be connected again.

If the panel could not reach the license server even once within 14 days of the
key being issued, everything works again after the first successful connection.

## Paid modules

Paid modules are licensed **separately** from the panel:

- when a module is installed, the system checks whether the owner of the API key
  has access to that module;
- the module is bound to the server it is installed on;
- modules are distributed in a protected form and can only be installed through
  the panel.

## FAQ

**I reinstalled the panel or moved it to a new server. Why did my key stop
working?**

The key is tied to a specific installation. After a clean reinstall or a
migration, the panel gets a new installation identifier, so the key must be
reissued. Please contact support.

**Can I use one key on several main servers?**

No. One key is valid for one installation. Additional servers (LBs) connected to
the main server do not need a separate key.

**My panel runs on a closed network without Internet access. What should I do?**

The server needs to reach the license server only once, within 14 days of the key
being issued. After that successful verification the license works offline with no
time limit. If even a single connection is not possible, contact us and we will
help find a suitable solution.

**Can I remove the XC_VM attribution if I bought a license?**

No. Attribution is mandatory whether or not an activation key is installed.

**How can I check the license status?**

The panel has a license status check. It verifies the license with the server
immediately and shows the current status.
