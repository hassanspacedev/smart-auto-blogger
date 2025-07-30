<?php
/**
 * Plugin Name:       Smart Auto Blogger
 * Plugin URI:        https://your-website.com/
 * Description:       Automatically imports, rewrites, and publishes posts from RSS feeds based on keywords.
 * Version:           2.4
 * Author:            Sohaib Hassan
 * Author URI:        https://hassanspace.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-auto-blogger
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly.

// --- Core Plugin Hooks ---

// Schedule the cron job on activation
register_activation_hook( __FILE__, 'sab_schedule_importer' );
function sab_schedule_importer() {
    if ( ! wp_next_scheduled( 'sab_hourly_import_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'sab_hourly_import_event' );
    }
}

// Clear the cron job on deactivation
register_deactivation_hook( __FILE__, 'sab_clear_importer_schedule' );
function sab_clear_importer_schedule() {
    wp_clear_scheduled_hook( 'sab_hourly_import_event' );
}

// Add the main function to the scheduled event
add_action( 'sab_hourly_import_event', 'sab_fetch_and_process_feeds' );


// --- Main Importer Logic ---

function sab_fetch_and_process_feeds() {
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );

    $feed_urls = get_option('sab_rss_feed_urls');
    $keywords = get_option('sab_keywords');
    
    if ( empty($feed_urls) || empty($keywords) ) {
        return;
    }

    $urls_array = explode( "\n", $feed_urls );
    $keywords_array = array_map('trim', explode(',', strtolower($keywords)));

    foreach ( $urls_array as $url ) {
        $url = trim($url);
        if ( empty($url) ) continue;

        $rss = fetch_feed($url);

        if ( is_wp_error($rss) ) {
            continue;
        }

        $max_items = $rss->get_item_quantity(10);
        $rss_items = $rss->get_items(0, $max_items);

        if ( $max_items == 0 ) continue;

        foreach ( $rss_items as $item ) {
            $post_title = esc_html($item->get_title());
            $post_content_raw = $item->get_content();
            $post_link = esc_url($item->get_permalink());

            // This uses the reliable built-in WordPress function to check for existing posts
            if ( post_exists($post_title) ) {
                continue;
            }

            $found_keyword = false;
            foreach ( $keywords_array as $keyword ) {
                if ( stristr(strtolower($post_title), $keyword) || stristr(strtolower(strip_tags($post_content_raw)), $keyword) ) {
                    $found_keyword = true;
                    break;
                }
            }
            
            if ( !$found_keyword ) {
                continue;
            }

            // 1. Rewrite content using your function
            $post_content = sab_rewrite_content($post_content_raw);

            // 2. Append a source link
            $post_content .= '<hr><p><strong>Source:</strong> <a href="' . $post_link . '" target="_blank" rel="nofollow noopener">' . $post_title . '</a></p>';
            
            // 3. Prepare new post data
            $new_post_args = array(
                'post_title'    => wp_strip_all_tags($post_title),
                'post_content'  => wp_kses_post($post_content),
                'post_status'   => 'publish', // <-- THIS IS THE CHANGE FOR FULL AUTOMATION
                'post_author'   => 1,
                'post_category' => array( (int) get_option('sab_post_category', 1) )
            );

            $post_id = wp_insert_post($new_post_args);

            // 4. Set featured image if post was created successfully
            if ( $post_id && !is_wp_error($post_id) ) {
                sab_set_featured_image_from_feed($item, $post_id);
            }
        }
    }
}

// --- Helper Functions (Your original logic, just with corrected names) ---

function sab_rewrite_content($content) {
    $synonyms = [
        'is' => ['is a', 'is actually'], 'are' => ['are often', 'are in fact'],
        'to' => ['to', 'in order to'], 'for' => ['for', 'for the purpose of'],
        'guide' => ['complete guide', 'ultimate guide', 'tutorial'],
        'best' => ['top', 'finest', 'recommended'], 'tips' => ['tricks', 'strategies', 'advice'],
        'how to' => ['how you can', 'a guide on how to'], 'easy' => ['simple', 'straightforward'],
        'fast' => ['quick', 'rapid'],
    ];

    foreach ($synonyms as $word => $replacements) {
        $content = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', $replacements[array_rand($replacements)], $content);
    }
    return $content;
}

function sab_set_featured_image_from_feed($item, $post_id) {
    $image_url = '';
    if ( $enclosure = $item->get_enclosure() ) {
        if ( strpos($enclosure->get_type(), 'image') !== false ) {
            $image_url = $enclosure->get_link();
        }
    }
    if ( empty($image_url) ) {
        $content = $item->get_content();
        preg_match('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $content, $matches);
        if ( isset($matches[1]) ) {
            $image_url = $matches[1];
        }
    }

    if ( !empty($image_url) ) {
        $attachment_id = media_sideload_image($image_url, $post_id, get_the_title($post_id), 'id');
        if ( !is_wp_error($attachment_id) ) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
}


// --- Admin Settings Page (Corrected names) ---

add_action('admin_menu', 'sab_add_settings_page');
function sab_add_settings_page() {
    add_options_page('Smart Auto Blogger Settings', 'Smart Auto Blogger', 'manage_options', 'smart-auto-blogger', 'sab_settings_page_html');
}

function sab_settings_page_html() {
    if ( !current_user_can('manage_options') ) return;
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('sab_settings_group');
            do_settings_sections('smart-auto-blogger');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'sab_settings_init');
function sab_settings_init() {
    register_setting('sab_settings_group', 'sab_rss_feed_urls');
    register_setting('sab_settings_group', 'sab_keywords');
    register_setting('sab_settings_group', 'sab_post_category');
    add_settings_section('sab_main_section', 'Main Importer Settings', null, 'smart-auto-blogger');
    add_settings_field('sab_rss_feed_urls_field', 'RSS Feed URLs (one per line)', 'sab_field_callback_urls', 'smart-auto-blogger', 'sab_main_section');
    add_settings_field('sab_keywords_field', 'Keywords (comma-separated)', 'sab_field_callback_keywords', 'smart-auto-blogger', 'sab_main_section');
    add_settings_field('sab_post_category_field', 'Default Post Category', 'sab_field_callback_category', 'smart-auto-blogger', 'sab_main_section');
}

function sab_field_callback_urls() {
    $setting = get_option('sab_rss_feed_urls');
    echo '<textarea name="sab_rss_feed_urls" rows="5" class="large-text">' . (isset($setting) ? esc_textarea($setting) : '') . '</textarea>';
}

function sab_field_callback_keywords() {
    $setting = get_option('sab_keywords');
    echo '<input type="text" name="sab_keywords" value="' . (isset($setting) ? esc_attr($setting) : '') . '" class="large-text">';
}

function sab_field_callback_category() {
    $setting = get_option('sab_post_category', 1);
    wp_dropdown_categories(['name' => 'sab_post_category', 'selected' => $setting, 'show_option_none' => 'Select a Category']);
}

add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'sab_add_settings_action_link' );
function sab_add_settings_action_link( $links ) {
    $settings_link = '<a href="options-general.php?page=smart-auto-blogger">' . __( 'Settings' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
