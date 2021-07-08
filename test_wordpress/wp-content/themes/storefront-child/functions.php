<?php
/**
 * Storefront engine room
 *
 * @package storefront
 */

add_action('init', 'start_session', 1); //start session
/**
 * Assign the Storefront version to a var
 */
$theme              = wp_get_theme( 'storefront' );
$storefront_version = $theme['Version'];

/**
 * Set the content width based on the theme's design and stylesheet.
 */
if ( ! isset( $content_width ) ) {
	$content_width = 980; /* pixels */
}

$storefront = (object) array(
	'version'    => $storefront_version,

	/**
	 * Initialize all the things.
	 */
	'main'       => require 'inc/class-storefront.php',
	'customizer' => require 'inc/customizer/class-storefront-customizer.php',
);

require 'inc/storefront-functions.php';
require 'inc/storefront-template-hooks.php';
require 'inc/storefront-template-functions.php';
require 'inc/wordpress-shims.php';

if ( class_exists( 'Jetpack' ) ) {
	$storefront->jetpack = require 'inc/jetpack/class-storefront-jetpack.php';
}

if ( storefront_is_woocommerce_activated() ) {
	$storefront->woocommerce            = require 'inc/woocommerce/class-storefront-woocommerce.php';
	$storefront->woocommerce_customizer = require 'inc/woocommerce/class-storefront-woocommerce-customizer.php';

	require 'inc/woocommerce/class-storefront-woocommerce-adjacent-products.php';

	require 'inc/woocommerce/storefront-woocommerce-template-hooks.php';
	require 'inc/woocommerce/storefront-woocommerce-template-functions.php';
	require 'inc/woocommerce/storefront-woocommerce-functions.php';
}

if ( is_admin() ) {
	$storefront->admin = require 'inc/admin/class-storefront-admin.php';

	require 'inc/admin/class-storefront-plugin-install.php';
}

/**
 * NUX
 * Only load if wp version is 4.7.3 or above because of this issue;
 * https://core.trac.wordpress.org/ticket/39610?cversion=1&cnum_hist=2
 */
if ( version_compare( get_bloginfo( 'version' ), '4.7.3', '>=' ) && ( is_admin() || is_customize_preview() ) ) {
	require 'inc/nux/class-storefront-nux-admin.php';
	require 'inc/nux/class-storefront-nux-guided-tour.php';
	require 'inc/nux/class-storefront-nux-starter-content.php';
}

/**
 * Note: Do not add any custom code here. Please use a custom plugin so that your customizations aren't lost during updates.
 * https://github.com/woocommerce/theme-customisations
 */



// Подсчет количества посещений страниц
add_action( 'wp_head', 'pageviews' );

/**
 * @param array $args
 *
 * @return null
 */
function pageviews( $args = [] ){
    global  $post, $wpdb;
        
    if( ! $post || ! is_singular() )
        return;
        
        $rg = (object) wp_parse_args( $args, [
            // Ключ мета поля поста, куда будет записываться количество просмотров.
            'meta_key' => 'views',
        ] );
              
        if (!isset($_SESSION["shows$post->ID"])) 
         { $_SESSION['shows'.$post->ID] = 1;
      
           $up = $wpdb->query( $wpdb->prepare(
             "UPDATE $wpdb->postmeta SET meta_value = (meta_value+1) WHERE post_id = %d AND meta_key = %s", $post->ID, $rg->meta_key
             ) );         
           if( ! $up )
             add_post_meta( $post->ID, $rg->meta_key, 1, true );             
           wp_cache_delete( $post->ID, 'post_meta' );                  
         } 
        else 
         { $_SESSION['shows'.$post->ID]++;
         }        
}

function update_date_good_last_bye( $product_id,$date_last_bye )
{  global  $wpdb;
    
 $args = [];
 $rg = (object) wp_parse_args( $args, [
    // Ключ мета поля поста, куда будет записываться дата покупки.
    'meta_key' => 'date_good_last_bye',
 ] );

 $query=$wpdb->prepare(
     "UPDATE $wpdb->postmeta SET meta_value = %s WHERE post_id = %d AND meta_key = %s",$date_last_bye, $product_id, $rg->meta_key
    );

 $up = $wpdb->query(  $query );
 if( ! $up )
    add_post_meta( $product_id, $rg->meta_key, $date_last_bye, true );
 wp_cache_delete( $product_id, 'post_meta' );
        
}


function start_session() 
{
    if(!session_id()) {
        session_start();
    }
}