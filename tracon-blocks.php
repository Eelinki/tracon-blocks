<?php
/**
 * Plugin name: Tracon Blocks
 * Description: Adds Kompassi integration blocks for WordPress
 * Author:      Eeli Hakkarainen
 * Version:     1.0.0
 * Text Domain: tracon-blocks
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Plugin_Tracon_Blocks
{
    public function __construct()
    {
        add_action( 'init', array( $this, 'init' ) );
        add_filter( 'block_categories_all', array( $this, 'block_categories_all' ), 10, 2 );
    }

    function init()
    {
        register_block_type(__DIR__ . '/blocks/artist-alley', array(
            'render_callback' => array( &$this, 'render_block_artist_alley' )
        ));
    }

    function get_artist_alley_data_rest($event_slug, $location, $day)
    {
        if (!function_exists('locale_get_primary_language')) {
            $locale = 'fi';
        } else {
            $locale = locale_get_primary_language(get_locale());
        }
        $qs = [
            'lang' => $locale,
        ];
        if (in_array($location, ['artist-alley', 'art-trail'])) {
            $qs['location'] = $location;
        }
        if (in_array($day, ['friday', 'saturday', 'sunday'])) {
            $qs['day'] = $day;
        }
        $qs = http_build_query($qs);

        $options = array(
            'http' => array(
                'header' => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n"
            )
        );
        $context = stream_context_create($options);
        $json = file_get_contents("https://kompassi.eu/api/v1/scopes/{$event_slug}/projections/artist-alley?{$qs}", false, $context);
        $response = json_decode($json, true);

        return $response;
    }

    function render_block_artist_alley($attributes)
    {
        if (strlen($attributes['eventSlug']) > 0) {
            $event_slug = $attributes['eventSlug'];
        }
        if (strlen($event_slug) < 1) {
            return null;
        }

        $location = $attributes['location'];
        $day = $attributes['day'];

        $html_attrs = array('class' => 'tracon-blocks',);

        $data = $this->get_artist_alley_data_rest($event_slug, $location, $day);

        ob_start();
        ?>

        <?= '<div id="tracon_block_artist_alley" ' . get_block_wrapper_attributes($html_attrs) . ' ' . wp_interactivity_data_wp_context($attributes) . '>' ?>
        <?php foreach ($data as $artist): ?>
        <div>
            <h3>
                <a href="<?= esc_url($artist['website']) ?>" target="_blank" rel="noopener ugc">
                    <?= esc_html($artist['name']) ?>
                </a>
            </h3>
            <p><?= esc_html($artist['formattedTableNumber']) ?></p>
        </div>
    <?php endforeach; ?>
        <?= '</div>' ?>

        <?php
        return ob_get_clean();
    }

    function block_categories_all( $categories, $editor_context ) {
        if( !$editor_context instanceof WP_Block_Editor_Context ) {
            return $categories;
        }

        $categories[] = array(
            'slug' => 'tracon',
            'title' => 'Tracon',
        );

        return $categories;
    }
}

new WP_Plugin_Tracon_Blocks();