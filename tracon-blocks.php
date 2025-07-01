<?php
/**
 * Plugin name: Tracon Blocks
 * Description: Adds Kompassi integration blocks for WordPress
 * Author:      Eeli Hakkarainen
 * Version:     1.2.0
 * Text Domain: tracon-blocks
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_Plugin_Tracon_Blocks')) {
    class WP_Plugin_Tracon_Blocks
    {
        public function __construct()
        {
            add_action('init', array($this, 'init'));
        }

        function init()
        {
            register_block_type(__DIR__ . '/blocks/artist-alley', array(
                'render_callback' => array($this, 'render_block_artist_alley')
            ));
            add_filter('block_categories_all', array($this, 'block_categories_all'), 10, 2);
            add_filter('the_content', array($this, 'tracon_header_ids'), 40);
            add_shortcode('tracon_artist_alley', array($this, 'tracon_artist_alley_shortcode'));
            add_shortcode('tracon_toc', array($this, 'tracon_table_of_contents_shortcode'));
        }

        function get_artist_alley_data_rest($event_slug, $location, $day)
        {
            if (!function_exists('locale_get_primary_language')) {
                $locale = 'fi';
            } else {
                $locale = locale_get_primary_language(get_locale());
            }

            $cache_key = 'tracon_artist_alley_' . md5($event_slug . $location . $day . $locale);

            // See if we have a cached response available
            $cached_response = get_transient($cache_key);
            if ($cached_response !== false) {
                return $cached_response;
            }

            $qs = [
                'lang' => $locale,
            ];
            if (in_array($location, ['artist-alley', 'art-trail'])) {
                $qs['location'] = $location;
            }
            if (in_array($day, ['friday', 'saturday', 'sunday'])) {
                $qs['days'] = $day;
            }
            $qs = http_build_query($qs);

            $response = wp_remote_get("https://kompassi.eu/api/v1/scopes/{$event_slug}/projections/artist-alley?{$qs}", array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ),
            ));
            if (is_wp_error($response)) {
                return [];
            }
            $json = wp_remote_retrieve_body($response);
            if (is_wp_error($json)) {
                return [];
            }
            $data = json_decode($json, true);

            set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);

            return $data;
        }

        function render_block_artist_alley($attributes)
        {
            if (empty($attributes['eventSlug'])) {
                return null;
            }
            $event_slug = $attributes['eventSlug'];

            $location = $attributes['location'];
            $day = $attributes['day'];

            $html_attrs = array('class' => 'tracon-blocks',);

            $data = $this->get_artist_alley_data_rest($event_slug, $location, $day);

            ob_start();
            ?>

            <?= '<div id="tracon_block_artist_alley" ' . get_block_wrapper_attributes($html_attrs) . ' ' . wp_interactivity_data_wp_context($attributes) . '>' ?>
            <?php foreach ($data as $artist): ?>
            <div>
                <p class="artist-name">
                    <a href="<?= esc_url($artist['website']) ?>" target="_blank" rel="noopener ugc">
                        <?= esc_html($artist['name']) ?>
                    </a>
                </p>
                <p><?= esc_html($artist['formattedTableNumber']) ?></p>
            </div>
        <?php endforeach; ?>
            <?= '</div>' ?>

            <?php
            return ob_get_clean();
        }

        function tracon_artist_alley_shortcode($attributes)
        {
            // Default attributes for the shortcode
            $a = shortcode_atts(array(
                'event_slug' => '',
                'location' => '',
                'day' => '',
            ), $attributes, 'tracon_artist_alley');

            // Map shortcode attributes to block attributes
            $block_attributes = array(
                'eventSlug' => $a['event_slug'],
                'location' => $a['location'],
                'day' => $a['day'],
            );

            return $this->render_block_artist_alley($block_attributes);
        }

        function block_categories_all($categories, $editor_context)
        {
            if (!$editor_context instanceof WP_Block_Editor_Context) {
                return $categories;
            }

            $categories[] = array(
                'slug' => 'tracon-blocks',
                'title' => 'Tracon',
            );

            return $categories;
        }

        function tracon_table_of_contents_shortcode()
        {
            $post = get_post();

            // Get the page contents and remove [tracon_toc] shortcodes to avoid infinite loops
            $content = get_post_field('post_content', $post->ID);
            $content = str_replace('[tracon_toc]', '', $content);

            // Pagebuilders (such as Divi) have headings internally stored as shortcodes, so execute them first
            $content = apply_filters('the_content', $content);

            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $headers = [];

            // Parse all headings from the content
            $xpath = new DOMXPath($dom);
            $header_nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

            foreach ($header_nodes as $header_node) {
                $level = (int)substr($header_node->tagName, 1);
                $id = $header_node->getAttribute('id');
                if (empty($id)) {
                    $id = sanitize_title($header_node->textContent);
                }
                $headers[] = [
                    'level' => $level,
                    'text' => trim($header_node->textContent),
                    'id' => $id
                ];
            }

            if (empty($headers)) {
                return '';
            }

            $output = '<div class="tracon-table-of-contents">';
            $output .= '<ul>';
            $output .= $this->toc_recurse($headers, 0);
            $output .= '</ul>';
            $output .= '</div>';

            return $output;
        }

        function toc_recurse(&$headers, $current_level)
        {
            $output = '';

            while (!empty($headers) && $headers[0]['level'] > $current_level) {
                $header = array_shift($headers);

                $output .= '<li>';
                $output .= '<a href="#' . esc_attr($header['id']) . '">' . esc_html($header['text']) . '</a>';

                if (!empty($headers) && $headers[0]['level'] > $header['level']) {
                    $output .= '<ul>';
                    $output .= $this->toc_recurse($headers, $header['level']);
                    $output .= '</ul>';
                }

                $output .= '</li>';
            }

            return $output;
        }

        function tracon_header_ids($content)
        {
            if (is_singular() && in_the_loop() && is_main_query()) {
                $dom = new DOMDocument();
                libxml_use_internal_errors(true);
                $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
                libxml_clear_errors();

                $xpath = new DOMXPath($dom);
                $header_nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

                foreach ($header_nodes as $header_node) {
                    if (!$header_node->hasAttribute('id')) {
                        $slug = sanitize_title($header_node->textContent);
                        $header_node->setAttribute('id', $slug);
                    }
                }

                $content = $dom->saveHTML();
            }
            return $content;
        }
    }

    new WP_Plugin_Tracon_Blocks();
}