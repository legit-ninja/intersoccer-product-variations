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
2. Repair: WooCommerce → **Order Meta Repair** → `intersoccer_update_order_metadata()` → `intersoccer_write_order_line_meta()` with `mode => repair`
3. Admin player assignment: direct meta updates on order items

Repair is **add-only** for most keys. **Correctable** when empty: Activity Type, Attendee DOB, Attendee Gender, Medical Conditions, **`assigned_player_id`**.

Optional repair flags (Scan & preview and Automated batch share the same options):

| Flag | Behavior |
|------|----------|
| `strip_deprecated` | Removes deprecated keys listed above when `assigned_player` is present |
| `prune_legacy_twins` (default **ON** in admin UI) | Collapses same-key duplicate rows; deletes reverse-map legacy labels when the EN canonical key is already present |

### Twin meta: prune A–C, keep D

| Class | Example | On prune |
|-------|---------|----------|
| **A** same-key multi-row | two `Activity Type` rows | Collapse to one value |
| **B** `wc_label` vs `order_meta_label` | `InterSoccer Venues` + `Sites InterSoccer` | Delete legacy (`InterSoccer Venues`) |
| **C** EN vs FR/DE label | `Lieux InterSoccer` when `Sites InterSoccer` present | Delete FR/DE legacy |
| **D** intentional dual-write | display label + `pa_*` / `attribute_pa_*` / `_camp_*` / `_intersoccer_canonical_*` | **Keep** (not bugs) |

Soft migrate (`intersoccer_normalize_legacy_order_meta_keys`) still runs without prune: it renames legacy → canonical only when the canonical key is empty. Prune (`intersoccer_prune_legacy_order_meta_twins`) additionally deletes remaining reverse-map twins.

Legacy admin URLs `intersoccer-update-orders` and `intersoccer-automated-updates` redirect to `intersoccer-order-meta-repair` (`tab=scan` / `tab=batch`).

## Historical migration playbook

1. **Player Management → Advanced → Backfill Player IDs** — assign `player_id` UUID to existing `intersoccer_players` rows.
2. **Product Variations → WooCommerce → Order Meta Repair** — Scan & preview or Automated batch (prune ON); resolves `assigned_player_id` from live PM data when only legacy `assigned_player` index exists.
3. **Reports & Rosters → Reconcile Rosters** — refresh roster DB for affected date range (separate post-step; RR code unchanged).

Dual-write during transition: new checkouts write both `assigned_player_id` and legacy `assigned_player`. Readers (RR `PlayerMatcher`) prefer UUID, then index fallback.

## Tool responsibilities

| Tool | Scope |
|------|--------|
| **Variation Health** | Product catalog attributes only; does not change orders (Program Manager) |
| **Order Meta Repair** | Order item meta repair from product/cart contract (scan + automated batch) |
| **Reconcile Rosters** | Sync roster DB from order meta (reports-rosters) |

After meta repair, `intersoccer_order_line_meta_repaired` triggers a targeted roster refresh in reports-rosters.

## PV writers vs RR readers

- **Writers (PV)** prefer EN canonical display labels (`Sites InterSoccer`, `Booking Type`, …) plus language-neutral `_intersoccer_canonical_*` / `_camp_*` dual-write targets.
- **Readers (RR)** keep dual-read aliases in `order-meta-keys.php` (FR/DE / wc_label fallbacks) so historical lines still resolve until repaired.
- Consistency rule: if RR already aliases a key that checkout/repair never writes, add the dual-write in PV only — do not remove RR aliases in this cut.

## SQL report keys (Final Reports)

| Field | Primary meta_key | Legacy fallback |
|-------|------------------|-----------------|
| Selected days | `Days Selected` | `Days of Week` |
| Gender | `Attendee Gender` | `gender`, `Player Gender` |
| Discount | `Discount` | `_applied_discounts` |
| Discount amount | `Discount Amount` | — |

Use `intersoccer_reports_sql_meta_key_candidates()` in reports-rosters for shared alias lists.

## Language-neutral canonical keys (Campaign Analytics / RR dual-read)

Display labels (`Activity Type`, `Booking Type`, …) remain for humans and may be localized. **Grouping and campaign analytics must prefer language-neutral slug keys** written alongside display labels at checkout.

| Meta key | Value format | Maps to |
|----------|--------------|---------|
| `_intersoccer_canonical_activity_type` | lowercase slug: `camp`, `course`, `tournament`, `birthday`, `event`, `other` | activity type (base; not Girls Only composite) |
| `_intersoccer_canonical_girls_only` | `0` or `1` | Girls Only boolean (separate from activity type) |
| `_intersoccer_canonical_booking_type` | slug: `full-week`, `single-days`, `full-term`, `buyclub`, `other` | booking type |
| `_intersoccer_canonical_venue` | venue term slug (EN default language) | venue |
| `_intersoccer_canonical_canton` | canton/region term slug | region |
| `_intersoccer_canonical_age_group` | age-group term slug | age group |
| `_intersoccer_canonical_camp_terms` | stable term slug **or** omit when `_camp_week_index` present | camp term label key |
| `_intersoccer_activity_slug` | optional alias of activity slug | legacy reader support |
| `_intersoccer_girls_only` | optional alias of girls_only `0`/`1` | legacy reader support |

**Already shipped (prefer for demand destination):**

| Meta key | Format |
|----------|--------|
| `_camp_start_date` / `Camp Start Date` | `Y-m-d` |
| `_camp_end_date` / `Camp End Date` | `Y-m-d` |
| `_camp_week_index` / `Camp Week Index` | 1-based integer, product/season-scoped |

**Writer status:** Readers in reports-rosters (`intersoccer_get_canonical_order_meta_field_map()`, RosterBuilder, Campaign `FacetNormalizer`) are ready. Product Variations must dual-write these keys at checkout/repair; until then RR normalizes display labels in one place only.

**Contract rules:**

1. Keys are underscore-prefixed and never translated.
2. Values are English-default term slugs or fixed enums — never HTML-entity-encoded display strings.
3. Girls Only is a boolean flag, never folded into `activity_type` as `Camp, Girls Only`.
4. Campaign Analytics and Final Numbers must key groupings off canonical values and use display labels only for rendering.
