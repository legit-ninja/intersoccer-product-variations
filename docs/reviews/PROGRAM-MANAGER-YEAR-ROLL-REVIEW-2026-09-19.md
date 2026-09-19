# Program Manager Year-Roll Review

**Date:** September 19, 2026  
**Reviewer:** Cloud Agent  
**Branch:** `cursor/program-manager-year-roll-review-e26b`  
**Scope:** Inventory and recommendations only — no production UI changes

---

## Executive Summary

This review addresses Jeremy's three asks about the Program Manager admin flow. The core catalog year-roll machinery is functional but has **significant UX friction points** around discoverability, copy clarity, and flow sequencing. The attribute auto-generation during year-roll has partial support but needs explicit lifecycle hooks. Order confirmation metadata on customer-facing pages has **critical gaps** with internal keys leaking to customers.

### P0 Recommendations (Critical)

1. **Order item meta: hide underscore-prefixed keys from customer-visible surfaces** (e-mails, thank-you page)
2. **Add wizard overlay / step-by-step copy to bulk Duplicate to year flow**
3. **Scaffold attributes alongside year-roll when attributes are missing on duplicated variations**

### P1 Recommendations (Important)

4. **Improve progress overlay messaging** — add "what's next" copy after bulk actions complete
5. **Add missing human labels** for `_camp_*` and `_intersoccer_canonical_*` keys or hide them
6. **Expose WPML Sync bulk action more prominently** post-Duplicate

### Soft-Later Recommendations

7. Auto-disable variations past camp end date (currently manual bulk-disable)
8. Link Scaffold directly from Duplicate success notice
9. Validate player/attendee PII visibility contract

---

## 1. Admin Flow to Generate Events for Upcoming Year

### Current UX: Step-by-Step Map

**Entry Point:** Products → Program Manager (list view)

1. **Select programs** — checkbox column, filter by Published/Drafts/Private/All
2. **Bulk Actions dropdown** — select "Duplicate to year…"
3. **Year/Season fields appear** inline beside the dropdown:
   - Year dropdown (existing `pa_program-year` terms) or custom input (e.g. `2027`)
   - Season dropdown (— Keep source — / Autumn / Winter / Spring / Summer)
4. **Apply** — submits, triggers JS progress overlay
5. **Progress overlay** shows "Processing N of M: {program name}" with live tallies (Processed / Skipped / Failed)
6. **Completion** — overlay shows "Bulk action complete", reloads page after 1.2s delay
7. **Success notice** lists newly created Draft links

**Post-Duplicate workflow (manual):**
- Navigate to each new Draft → Detail view
- Edit dates/terms/prices
- Click **Sync all languages (WPML)** to create/refresh FR/DE translations
- Change Status dropdown from Draft → Publish, click **Update status**

### Friction Points Identified

| Issue | Location | Evidence |
|-------|----------|----------|
| **No wizard guidance** | List bulk action | User must know to (a) Duplicate first, (b) then edit details, (c) then WPML Sync, (d) then Publish. No tooltip or sequencing copy. |
| **Progress overlay generic** | `pmFinishBulkProgress()` | Shows "Bulk action complete" without next-steps hint. User doesn't know WPML Sync is needed. |
| **Duplicate creates skeletons without schedule** | `duplicate_program()` L2774-2799 | Prices and `_camp_start_date`/`_camp_end_date` are not copied — comment says this is intentional, but no warning shown. |
| **Scaffold not auto-triggered** | Post-Duplicate | If source had no variations or attributes are incomplete, duplicate inherits gaps. Scaffold is available but not chained. |
| **Year field validation delayed** | `pmBulkSprintf` in JS | Error "Select or enter a target program year" only fires on form submit, not on dropdown change. |
| **Refresh button visibility** | Detail view "Refresh Attributes" | Only visible when unhealthy variations exist — user may not know it's available. |

### Recommended UI Updates (Soft-STAMP Style)

| Rec ID | Type | Description | Files Affected |
|--------|------|-------------|----------------|
| **R1.1** | Copy | Add helper text below bulk dropdown: _"Creates Draft clones. After duplicating, edit dates & prices in each program's detail view, then Sync WPML and Publish."_ | `program-manager.php` L595-618 |
| **R1.2** | Modal | Replace generic "Bulk action complete" with contextual success: _"Duplicated {N} programs to Draft (year {Y}). Next: open each program to edit schedule/prices, then Sync WPML."_ Include links to new products. | `program-manager.js` L997-1010, L1000 (pmFinishBulkProgress) |
| **R1.3** | Button | Add "Scaffold Missing Variations" in success notice after Duplicate when target products have zero variations. | `process_bulk_item()` L2447-2456 (add notice for scaffold-needed) |
| **R1.4** | Validation | Show real-time validation message when Year dropdown is empty and action is `duplicate_to_year`. | `program-manager.js` L1145-1150 (pmToggleBulkYearRoll) |
| **R1.5** | Guide | Add numbered stepper on Detail view ("1. Edit Details → 2. Sync WPML → 3. Publish") or a banner summarizing lifecycle. | `render_detail_view()` after L667 |

---

## 2. Auto-Generate Product Attributes During Year-Roll

### What Exists Today

| Feature | Status | File:Line | Notes |
|---------|--------|-----------|-------|
| **Scaffold variations** | ✅ Exists | `ajax_scaffold_variations()` L1635-1720 | Creates variations from type-specific matrix (ages × bookings × times for camp; days × ages × venues for course). |
| **Attribute sync** | ✅ Exists | `intersoccer_attr_sync_to_woocommerce()` | Creates missing global WC attributes from registry; seeds default terms. |
| **Attribute enforcement** | ✅ Exists | `attribute-enforcement.php` | Blocks non-registry attributes; validates term shapes; enforces required parent attrs for Publish. |
| **Refresh attributes (list bulk)** | ✅ Exists | `process_bulk_item('refresh_attrs')` L2344-2366 | Repairs variation facets (camp) or resets variation attrs to parent defaults (other types). |
| **Auto-gen on Duplicate** | ⚠️ Partial | `duplicate_program()` L2775-2799 | Clones variations but does NOT copy prices or schedule meta. Does NOT auto-run Scaffold if source had none. |
| **Year term auto-create** | ✅ Exists | `ensure_program_year_term()` L2614-2648 | Creates `pa_program-year` term (e.g. `2027`) if it doesn't exist. |
| **Season term validation** | ✅ Exists | L2716-2730 | Blocks year-qualified season terms ("Autumn 2027"); enforces evergreen seasons. |

### Gaps vs "Auto-generate attributes when rolling year"

1. **Scaffold not chained after Duplicate** — User must manually click "Auto-generate Variations" on each new product if source had none or matrix changed.
2. **Parent attributes not auto-filled** — Duplicate copies source attributes verbatim. If source was missing `pa_program-year`, target inherits the gap. Year term IS assigned (L2769-2772), but other required attrs may be empty.
3. **Variation meta not seeded** — Course meta (`_course_start_date`, `_course_total_weeks`, `_course_holiday_dates`) and camp schedule (`_camp_start_date`, `_camp_end_date`, `_camp_week_index`) are intentionally NOT copied. User must fill manually via Camp Schedule tools or Course Tools.
4. **Attribute health not validated post-Duplicate** — Completeness check runs only on read (list/detail view), not as a warning during/after duplicate.

### Recommended Tips

| Rec ID | Type | Description | Files Affected |
|--------|------|-------------|----------------|
| **R2.1** | Flow | After `duplicate_program()` succeeds, run `get_default_matrix($type)` diff against existing children. If new SKUs are needed, either auto-scaffold or add notice "N variations missing — Scaffold?" | `process_bulk_item('duplicate_to_year')` L2447-2456 |
| **R2.2** | Guard | In `duplicate_program()`, validate that required parent attrs on source exist; warn or copy Year/Season before creating. | L2667-2770 (pre-save validation) |
| **R2.3** | Meta | When Duplicate creates variations, seed empty `_course_start_date` / `_camp_start_date` fields with placeholder comment meta OR expose "Apply template schedule" tool. | New helper or extend `create_single_variation()` |
| **R2.4** | Attributes | The `pa_*` attributes that should be auto-generated or verified per type: |
| | | **Camp:** `pa_activity-type`, `pa_intersoccer-venues`, `pa_program-season`, `pa_program-year`, `pa_age-group`, `pa_canton-region`, `pa_city`, `pa_girls-only`, `pa_days-of-week`, `pa_camp-terms`, `pa_camp-times` | |
| | | **Course:** same core + `pa_course-day`, `pa_course-times` | |
| | | Year is `pa_program-year` (bare YYYY slug); seasons are evergreen (`autumn`/`winter`/`spring`/`summer`). | |

---

## 3. Order Item Metadata on Confirmation Page (Customer-Facing)

### What Customers See Today

WooCommerce renders order item meta on:
- **Thank-you page** (post-checkout)
- **Order-received emails** (processing/completed)
- **My Account → Orders → View Order**

The rendering uses `wc_display_item_meta()` which shows all item meta **except keys prefixed with underscore `_`** unless explicitly filtered.

### Inventory of Order Meta Keys Written

From `intersoccer_write_order_line_meta()` and `intersoccer_build_order_line_meta()`:

| Meta Key | Human-Visible? | Customer Should See? | Notes |
|----------|---------------|---------------------|-------|
| `Activity Type` | ✅ Yes | ✅ Yes | "Camp", "Course, Girls Only", etc. |
| `Season` | ✅ Yes | ✅ Yes | Evergreen season label |
| `Assigned Attendee` | ✅ Yes | ✅ Yes | Player name |
| `Attendee DOB` | ✅ Yes | ⚠️ Debatable | PII — may want to hide |
| `Attendee Gender` | ✅ Yes | ⚠️ Debatable | PII — may want to hide |
| `Medical Conditions` | ✅ Yes | ⚠️ Debatable | PII — may want to hide |
| `Sites InterSoccer` | ✅ Yes | ✅ Yes | Venue (registry label) |
| `Booking Type` | ✅ Yes | ✅ Yes | Full Week / Single Day(s) |
| `Age Group` | ✅ Yes | ✅ Yes | |
| `Days Selected` | ✅ Yes | ✅ Yes | Customer-selected camp days |
| `Late Pickup Type` | ✅ Yes | ✅ Yes | |
| `Late Pickup Days` | ✅ Yes | ✅ Yes | |
| `Late Pickup Cost` | ✅ Yes | ✅ Yes | |
| `Camp Start Date` | ✅ Yes | ✅ Yes | Human label |
| `Camp End Date` | ✅ Yes | ✅ Yes | Human label |
| `Camp Week Index` | ✅ Yes | ⚠️ Maybe not | Internal reference |
| `Start Date` (course) | ✅ Yes | ✅ Yes | |
| `End Date` (course) | ✅ Yes | ✅ Yes | |
| `Holidays` | ✅ Yes | ✅ Yes | |
| `Discount` | ✅ Yes | ✅ Yes | Discount note |
| `Discount Amount` | ✅ Yes | ✅ Yes | |
| `Girls Only` | ✅ Yes | ✅ Yes | When assigned |
| `assigned_player` | ✅ Yes | ❌ No | Internal legacy index |
| `assigned_player_id` | ✅ Yes | ❌ No | Internal UUID |
| `attribute_pa_*` | ✅ Yes | ❌ No | Internal taxonomy keys |
| `_camp_start_date` | ❌ Hidden | ❌ N/A | Underscore prefix hides it |
| `_camp_end_date` | ❌ Hidden | ❌ N/A | |
| `_camp_week_index` | ❌ Hidden | ❌ N/A | |
| `_intersoccer_canonical_*` | ❌ Hidden | ❌ N/A | Language-neutral analytics keys |

### Findings: Issues for Customer-Facing Surfaces

| Issue | Severity | Evidence | Location |
|-------|----------|----------|----------|
| **`assigned_player` leaks** | 🔴 Critical | Non-underscore key written at checkout. WC shows it as "assigned player: 0" or similar. | `intersoccer_apply_assigned_player_order_meta()` L328 |
| **`assigned_player_id` leaks** | 🔴 Critical | UUID visible to customer in My Account / emails. | L330, L343 |
| **`attribute_pa_*` keys leak** | 🟠 High | `intersoccer_collect_variation_taxonomy_meta()` writes `attribute_pa_booking-type`, etc. Customer sees raw key. | L779-801 |
| **`Camp Week Index` visible** | 🟡 Medium | Internal week number (1, 2, 3…) may confuse customers expecting date. | `intersoccer_camp_schedule_order_meta_labels_en()` |
| **PII displayed** | 🟡 Medium | DOB/Gender/Medical shown on confirmation. Privacy risk if screenshot shared. | L340-350 |
| **Duplicate keys if prune off** | 🟡 Medium | Without prune, `Sites InterSoccer` + `pa_intersoccer-venues` both appear. | `intersoccer_prune_taxonomy_attribute_twins()` |

### Contract vs Reality

Per `docs/ORDER-META-CONTRACT.md`:
- **Writers prefer EN canonical display labels** (`Sites InterSoccer`, `Booking Type`)
- **Underscore-prefixed keys** (`_camp_*`, `_intersoccer_canonical_*`) are never translated and should be hidden

**Reality check:** Most underscore keys ARE hidden. But `assigned_player`, `assigned_player_id`, and `attribute_pa_*` are NOT underscore-prefixed, so WooCommerce displays them.

### Recommended Tips

| Rec ID | Type | Description | Files Affected |
|--------|------|-------------|----------------|
| **R3.1** | Filter | Add `woocommerce_order_item_get_formatted_meta_data` filter to hide: `assigned_player`, `assigned_player_id`, `attribute_pa_*`, `pa_*`. | New filter in `order-meta-contract.php` or `checkout-calculations.php` |
| **R3.2** | Underscore | Rename `assigned_player` → `_assigned_player` and `assigned_player_id` → `_assigned_player_id` for new orders (dual-write during migration). | `intersoccer_apply_assigned_player_order_meta()` |
| **R3.3** | Cleanup | Stop writing `attribute_pa_*` keys to order meta when the human label is already written. `intersoccer_write_order_line_meta()` L889-912 has partial logic but doesn't fully suppress. | L889-914 |
| **R3.4** | PII | Add setting to hide `Attendee DOB`, `Attendee Gender`, `Medical Conditions` from customer-visible order meta (show only in admin). | Filter or new option |
| **R3.5** | Doc | Update `ORDER-META-CONTRACT.md` to explicitly list keys that MUST be hidden from customer surfaces. | `docs/ORDER-META-CONTRACT.md` |

---

## Prioritized Recommendations Summary

### P0 — Must Fix (Customer-Facing / Data Leak)

| ID | Recommendation |
|----|----------------|
| R3.1 | Filter to hide `assigned_player`, `assigned_player_id`, `attribute_pa_*` from customer order meta display |
| R3.2 | Underscore-prefix internal player keys for new orders |
| R1.2 | Replace generic bulk-complete message with contextual next-steps |

### P1 — Important (Admin UX / Workflow)

| ID | Recommendation |
|----|----------------|
| R1.1 | Add inline help text to Duplicate to year bulk action |
| R2.1 | Chain Scaffold check after Duplicate when variations missing |
| R1.5 | Add lifecycle stepper/banner on Detail view |
| R3.3 | Stop writing redundant `attribute_pa_*` keys |

### Soft-Later — Nice to Have

| ID | Recommendation |
|----|----------------|
| R1.3 | Add Scaffold link in success notice |
| R1.4 | Real-time Year validation on dropdown |
| R2.2 | Pre-validate required parent attrs before duplicate |
| R2.3 | Template schedule seeding for new year |
| R3.4 | PII visibility setting |
| R3.5 | Order-meta-contract doc update |

---

## Soft-Glance Hooks

**For Pixel (Admin PM Flow):**
- `render_list_view()` L529-623 — bulk action UI
- `pmFinishBulkProgress()` JS L975-1012 — completion overlay
- `render_detail_view()` L630-1070 — program detail / status / tools
- `process_bulk_item('duplicate_to_year')` L2404-2456

**For Tess (Confirmation Page):**
- `intersoccer_write_order_line_meta()` in `order-meta-contract.php` L832-952
- `intersoccer_apply_assigned_player_order_meta()` L319-350
- `intersoccer_collect_variation_taxonomy_meta()` L778-804
- Need new: `woocommerce_order_item_get_formatted_meta_data` filter

---

## Appendix: File References

| File | Lines | Purpose |
|------|-------|---------|
| `includes/woocommerce/program-manager.php` | 1-3755 | Main PM class: list, detail, create, duplicate, bulk actions |
| `js/program-manager.js` | 1-1677 | Wizard nav, AJAX, bulk progress overlay |
| `css/program-manager.css` | 1-112 | Bulk progress modal, variation row styling |
| `includes/woocommerce/attribute-registry.php` | 1-652 | Canonical attribute definitions, templates, health requirements |
| `includes/woocommerce/attribute-sync.php` | 1-462 | WC attribute sync, term seeding |
| `includes/woocommerce/attribute-enforcement.php` | 1-638 | Attribute validation, term-shape enforcement |
| `includes/woocommerce/pm-wpml-sync.php` | 1-554 | WPML translation sync for products/variations |
| `includes/woocommerce/order-meta-contract.php` | 1-1474 | Order line meta builder, canonical keys, repair |
| `includes/woocommerce/checkout-calculations.php` | 303-390 | Checkout order item meta writer |
| `docs/ORDER-META-CONTRACT.md` | 1-114 | Meta key contract documentation |

---

*Report generated by Cloud Agent. PR opened for review documentation only.*
