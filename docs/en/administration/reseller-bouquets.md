# Reseller bouquet selection and customer order

The administrator defines which bouquets a reseller may assign. The reseller
chooses bouquets and their order for an individual customer. Bouquet contents
and the global bouquet order remain managed by the administrator.

## Set the reseller's allowed bouquets

1. Open **Users**, edit the reseller, and find **Reseller bouquet access**.
2. Choose **Only selected bouquets** and select the permitted bouquets.
3. Save the reseller.

For example, reseller 3 can be given bouquets **5–10**, while reseller 4 can be
given **10–15**. Select the actual bouquet IDs in the list; these are inclusive
ranges in this example, not text entered into the field.

**All package bouquets** removes the additional reseller limit. An empty list
with **Only selected bouquets** allows no bouquets. Editing a reseller's limit
leaves existing customers unchanged immediately. It takes effect when a new
customer is created or an existing customer is next saved.

The reseller group still needs **Allow change bouquets** to change a customer's
selection or order. Without that permission, package defaults apply within the
reseller's limit. A reseller cannot change their own allowed list through the
customer form or reseller API.

## Package defaults for Create Line and Generate Trial

Packages remain the source of the default selection. Both **Create Line** and
**Generate Trial** preselect the package's bouquets, limited by the reseller's
allowed list. The reseller's permission list does not add bouquets absent from
the package.

| Package bouquets | Reseller allowance | Default selection |
| --- | --- | --- |
| 1–10 | 5–10 | 5–10 |
| 10–15 | 10–15 | 10–15 |
| 1–4 | 5–10 | None |
| 1–10 | All package bouquets | 1–10 |

## Select and reorder for one customer

1. Create a line, generate a trial, or edit an existing customer.
2. Choose the package for a new subscription. On an existing customer, keep
   **No Changes** when only editing bouquets.
3. Open the **Bouquets** tab. Select the bouquets this customer should receive.
4. Use **↑** and **↓** beside a bouquet to change its position.
5. Save the customer. Changing the package also follows the usual purchase and
   credit rules; a bouquet-only edit does not buy an additional period.

For example, two customers of reseller 3 can receive `[5, 7, 10]` and
`[10, 5, 7]`. Their order is stored separately. The order of streams within each
bouquet remains unchanged. A stream present in more than one selected bouquet
appears at its first occurrence.

The saved order is used by generated playlists and supported panel player/API
listings. Refresh the playlist or reconnect the player after saving. An external
player that applies its own sorting may display a different order.

## Enforcement and installation

The server checks package membership, reseller allowance, and the customer's
owner when saving. Changing request fields cannot grant additional bouquets.
When a parent reseller edits another reseller's customer, both the editing
reseller's allowance and the target owner's allowance apply.

Deploy the matching core changes and run the normal database migration process
on MAIN (`php console.php db:migrate` from the application directory). Migration
`094_add_reseller_bouquet_permissions.sql` adds the reseller permission field.
This feature is part of the core, independently of the User Proxy module.
