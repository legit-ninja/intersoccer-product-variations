<?php

/**
 * Custom Checkout Template for InterSoccer
 */

get_header('shop');

?>

<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <?php
        // Output the default WooCommerce checkout form
        wc_get_template('checkout/form-checkout.php', array('checkout' => WC()->checkout()));

        // Output the player assignment form
        echo intersoccer_render_player_assignment_fields();
        ?>
    </main>
</div>

<?php

get_footer('shop');
?>
