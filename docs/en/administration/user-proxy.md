# User Proxy: installation and setup

User Proxy assigns a registered proxy to an individual customer. Customers without
an assignment use native playback while this module is enabled. If the assigned
proxy cannot serve the selected origin server, a new playback request falls back
to native playback.

## Install the core and module

1. Deploy the working core based on **2.6.6-p6**, including the User Proxy routing
   and customer-form hooks. The unmodified upstream release does not contain
   these hooks.
2. Obtain the separately distributed **User Proxy ZIP**. Modules are installed
   through the panel; their source is deliberately excluded from the core Git
   repository.
3. Open the panel's module manager, upload the ZIP, and install and enable
   **User Proxy**. The module checks that the required core hooks are present.
4. Confirm that **User Proxy** appears in the panel navigation.

## Configure the proxy server

1. Register/install the proxy through **Servers → Manage Proxies** and enable it.
2. In the proxy's server assignment, **select all origin servers**, including
   MAIN if it serves streams and all load balancers. Save/apply the assignment.
   Repeat this for each proxy offered to customers.
3. Check that the proxy is online, its address and playback ports are correct,
   and its maximum client count allows the expected traffic.
4. Whenever you add an origin server, include it in every applicable proxy's
   server assignment and apply the updated configuration.

The server assignment is essential: the module only selects a proxy registered
for the origin that serves the requested stream. A missing assignment results
in native playback for that origin, even if the proxy itself is online.
Geographic/ISP restrictions and the existing capacity selector also still apply.

## Assign a proxy to a customer

Administrators can open **User Proxy**, find the customer, choose a registered
proxy, and save. Alternatively, edit an existing customer and use **Playback
proxy** under Advanced settings. Authorized resellers see the same controls
under Restrictions, or a separate Playback options tab when Restrictions is
unavailable.

Enable **Use proxy**, choose the proxy, and click **Save playback**. Customer
account details are saved separately. The assignment applies to the next
playback request; reconnect the player to test it.

Turn **Use proxy** off and save to restore native playback for that customer.
This removes the assignment, without deleting the proxy server.

## Verify the setup

The dashboard's **Server Status → Proxies** row shows how many enabled proxies
are online and names those that are offline. Disabled proxies and proxies being
installed or updated are excluded. The row is hidden when no proxies are registered;
registered but disabled proxies produce an inactive row.

Test a customer with an assigned proxy against streams on different origins.
Confirm the selected route in the panel's connection information. Then test an
unassigned customer and an unavailable proxy to confirm native fallback. A
client that caches a playlist or an existing connection may need to reconnect.

Assignments are kept in `config/user-proxy/assignments.json`, outside the
replaceable module directory. Include this directory in backups. The panel
must be able to write it, and the streaming process must be able to read it.
If the module is disabled or removed, the core's normal server proxy rules
apply again.
