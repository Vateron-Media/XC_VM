# User Proxy

User Proxy assigns a registered proxy per customer. Install the panel core with
its User Proxy hooks first; unmodified **2.6.6-p6** does not include them.

## Install and configure

1. Upload the separately supplied ZIP in the panel's module manager. Install
   and enable **User Proxy**.
2. Register and enable the proxy under **Servers → Manage Proxies**.
3. In its server assignment, **select ALL origin servers**, including MAIN
   when it serves streams and every load balancer. Save/apply the configuration.
   Add new origin servers to this assignment later as well.
4. Check the proxy's online status, address, playback ports and client capacity.

The proxy must support the requested origin. Capacity and geographic/ISP rules
also apply; an online proxy alone does not guarantee that it can be selected.

## Assign and verify

Open **User Proxy**, choose a customer and proxy, and save. Alternatively, edit
an existing customer: use **Playback proxy** under Advanced settings (admin),
or Restrictions/Playback options (authorized reseller). Enable **Use proxy**,
select the proxy and click **Save playback**, separately from customer details.
Reconnect the player and check the route in **Live Connections**.

While the module is enabled, unassigned customers and unavailable/incompatible
assignments fall back to native playback. Turn **Use proxy** off and save to
remove a customer's assignment. Disabling the module restores normal core proxy
rules.

**Dashboard → Server Status → Proxies** shows enabled proxies' online/offline
status, excluding installation/update states. With no registered proxies, the
row is hidden; registered but disabled proxies show an inactive row.

Back up `config/user-proxy/assignments.json`. The panel must be able to write it,
and streaming processes must be able to read it.
