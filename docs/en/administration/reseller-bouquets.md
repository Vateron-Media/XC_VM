# Reseller bouquets and customer order

Admins set each reseller's bouquet allowance; resellers select and sort bouquets
per customer. Bouquet contents and global order remain managed by the admin.

## Set the allowance

In **Users → Edit reseller → Reseller bouquet access**, choose **Only selected
bouquets**, select the allowed bouquets and save. For example, allow reseller 3
bouquets 5–10 and reseller 4 bouquets 10–15.

**All package bouquets** removes the extra limit. An empty **Only selected
bouquets** list permits none. New limits apply to new customers or the next
customer save; existing customers are not immediately changed.

The reseller group needs **Allow change bouquets** to select or reorder.
**Create Line** and **Generate Trial** default to the package's bouquets within
that reseller's allowance: package 1–10 with allowance 5–10 defaults to 5–10.
Bouquets outside the package cannot be added. Without permission, these defaults
apply. When editing another reseller's customer, both resellers' limits apply.

## Select and reorder

1. Create a line/trial or edit a customer. For a bouquet-only edit, keep the
   package set to **No Changes**.
2. Open **Bouquets**, select the customer's bouquets and move them with **↑/↓**.
3. Save and refresh the playlist or reconnect the player.

Each customer's order is separate. Streams retain their order within a bouquet;
duplicates appear at their first occurrence. A saved empty selection stays
empty. Category-template edits preserve the customer's bouquet order. Players
using their own sorting may display a different order. Changing the package
still follows normal purchase and credit rules.

## Deploy

Install the matching core and run `php console.php db:migrate` on MAIN from the
application directory. Migration `094_add_reseller_bouquet_permissions` adds the
allowance; `096_add_customer_bouquet_order` stores the order flag independently
of category templates and migrates legacy markers. If a concurrent edit or stale
form is refused, reload and apply the change again. This feature needs no proxy
module.
