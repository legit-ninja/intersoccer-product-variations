# Order Meta Contract

Canonical order item metadata for InterSoccer bookings. **Writer:** `intersoccer-product-variations` (`order-meta-contract.php`). **Readers:** `intersoccer-reports-rosters` (`order-meta-keys.php`, `RosterBuilder`).

## Semantic rules

| Concept | Product / variation | Order item meta |
|--------|---------------------|-----------------|
| Camp weekdays offered | `pa_days-of-week` → label **Days of Week** | — |
| Customer camp day pick | — | **Days Selected** |
| Player reference | — | **`assigned_player_id`** (UUID, canonical) + `assigned_player` (legacy index) |
| Player display | — | **Assigned Attendee** |
| Player PII | — | **Attendee DOB**, **Attendee Gender**, **Medical Conditions** |

Deprecated keys (strip on repair): `Variation ID`, `Base Price`, `Remaining Sessions`, `Player Index`, `intersoccer_player_index`.

## Write path

1. Checkout: `intersoccer_write_order_line_meta()` with `mode => checkout`
2. Repair: Find Order Issues → `intersoccer_write_order_line_meta()` with `mode => repair`
3. Admin player assignment: direct meta updates on order items

Repair is **add-only** for most keys. **Correctable** when empty: Activity Type, Attendee DOB, Attendee Gender, Medical Conditions, **`assigned_player_id`**.

## Historical migration playbook

1. **Player Management → Advanced → Backfill Player IDs** — assign `player_id` UUID to existing `intersoccer_players` rows.
2. **Product Variations → Find Order Issues** — repair order lines; resolves `assigned_player_id` from live PM data when only legacy `assigned_player` index exists.
3. **Reports & Rosters → Reconcile Rosters** — refresh roster DB for affected date range.

Dual-write during transition: new checkouts write both `assigned_player_id` and legacy `assigned_player`. Readers (RR `PlayerMatcher`) prefer UUID, then index fallback.

## Tool responsibilities

| Tool | Scope |
|------|--------|
| **Variation Health** | Product catalog attributes only; does not change orders |
| **Find Order Issues** | Order item meta repair from product/cart contract |
| **Reconcile Rosters** | Sync roster DB from order meta |

After meta repair, `intersoccer_order_line_meta_repaired` triggers a targeted roster refresh in reports-rosters.

## SQL report keys (Final Reports)

| Field | Primary meta_key | Legacy fallback |
|-------|------------------|-----------------|
| Selected days | `Days Selected` | `Days of Week` |
| Gender | `Attendee Gender` | `gender`, `Player Gender` |
| Discount | `Discount` | `_applied_discounts` |
| Discount amount | `Discount Amount` | — |

Use `intersoccer_reports_sql_meta_key_candidates()` in reports-rosters for shared alias lists.
