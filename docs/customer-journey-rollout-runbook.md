# Customer Journey Attribution Rollout Runbook

Use this runbook after plugin 1.7.5 is installed. It does not authorize a
production setting change by itself; assign an owner and rollback contact
before beginning.

## Preconditions

- The current Worker signing key and the production plugin current key match.
- Signature verification has remained in **Audit only** for the agreed
  observation window after the corrected-key rollout.
- Diagnostics show normal `valid_current` traffic and no unexplained increase
  in `invalid`, `malformed`, or `unverifiable` counts.
- The starting diagnostic counts and current settings are recorded.
- A controlled test order can be created and refunded if needed.

## 1. Enforce signed campaign attribution

1. Record the current Signature diagnostics totals and screenshots of the
   settings page.
2. Change **Signature verification** from **Audit only** to **Enforce**.
   Keep **First/latest touch state** unchecked.
3. In a fresh private browser session, open a newly issued signed short link.
   Verify the normal landing page and existing legacy `cp_*` cookies are set.
4. Create a controlled checkout/order. Confirm existing `_cp_from`, `_cp_slid`,
   source-specific ID, channel variant, and placement metadata still appear.
5. Test an unsigned, altered, or expired `cp_*` URL in another fresh private
   session. It must not set or replace trusted attribution.
6. Check diagnostics and WooCommerce/PHP error logs. Do not retain full URLs,
   signatures, or email values in operational notes.

### Rollback

Set **Signature verification** back to **Audit only**. Existing attribution
cookies and order metadata are not deleted.

## 2. Enable first/latest touch state

Only continue after Step 1 succeeds.

1. Record the current settings and diagnostic totals.
2. Enable **First/latest touch state** and save. Leave cookie lifetime at the
   approved value (currently 30 days).
3. In a fresh private browser session, open a newly issued signed campaign
   link. Confirm `cp_attribution_first` and `cp_attribution_latest` exist and
   are `Secure`, `HttpOnly`, and `SameSite=Lax`.
4. Open a later valid signed link from a different source. Confirm first touch
   is unchanged and latest touch changes. Confirm a direct visit changes
   neither snapshot.
5. Complete a controlled order using each enabled checkout type (classic and
   block checkout, if both are in use).
6. In wp-admin, confirm legacy `_cp_*` metadata still reflects latest touch and
   verify the **Attribution snapshots** section shows:
   - `_cp_first_touch`
   - `_cp_latest_touch`
   - `_cp_conversion_touch`
   - `_cp_conversion_touch_timestamp`
7. Confirm no new PHP/WooCommerce errors and that existing order attribution
   reporting continues to read legacy metadata.

### Rollback

Uncheck **First/latest touch state** and save. This stops new snapshot writes
and restores the prior browser-mirroring behavior; it does not delete existing
snapshot metadata or legacy order metadata.

## Post-release window

- Observe diagnostics and WooCommerce/PHP logs for the agreed reconciliation
  period.
- Record order IDs only where operationally necessary; never copy signatures,
  full campaign URLs, raw email, or form data into the runbook or tickets.
- Update the central customer-journey implementation checklist with the
  deployment, acceptance-test result, rollback owner, and any exception.
