# Girls-only rejection messages (gettext + WPML)

The add-to-cart rejection strings in `includes/woocommerce/girls-only-verification.php` use the `intersoccer-product-variations` text domain and are compiled from `lang/intersoccer-product-variations-{de,fr}.po` into `.mo` files (including `de_DE` and `fr_FR` copies).

If these English source strings are also registered in **WPML String Translation** (database), add matching **German** and **French** translations there with the same meaning as the `.po` entries. This repository change does not modify the WordPress database.
