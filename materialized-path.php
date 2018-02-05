<?php
/*
	Plugin Name: Materialized Paths
	Plugin URI: http://crumbls.com
	Description: Implement materialized paths for WordPress to enable simple searching for child and parent paths.
	Author: Chase C. Miller
	Version: 2.0.1a
	Author URI: http://crumbls.com
	Text Domain: Crumbls\Plugins\Materialized
 */


/**
* Usage:  To search by path, add a parameter via pre_get_posts or any wp_query that has the same name as Plugin::$search_field.
* It may be an array of post IDs or a single one.  That will search for any children of the entries.
* For example:
* add_filter('pre_get_posts', function($q) { $q->set('post_parent__in', [1]); }, 10, 1);
* This will make it so the path must match whatever the materialized path of post 1 is.
**/
namespace Crumbls\Plugins\Materialized;

defined('ABSPATH') or exit(1);

global $cache;

class Plugin
{
    /**
     * Define post types that you want to store materialized path's for here.
     * If it's empty, it will auto populate with all hierarchal post types.
     **/
    protected $post_types = ['page', 'post'];
    // Define a $_POST field for your search to limit queries.
    protected $search_field = 'post_parent__in';

    /**
     * Plugin constructor.
     */
    
    public function __construct()
    {
        add_action('init', [$this, 'init'], PHP_INT_MAX);
        add_action('save_post', [$this, 'savePost'], 11, 1);
        add_action('pre_get_posts', [$this, 'preGetPosts'], 11, 1);
    }

    /**
     * Initializer
     */
    public function init()
    {
        if (!$this->post_types) {
            /**
             * If post_types is not defined, take all hierarchal post types.
             */
            $this->post_types = array_keys(array_filter(get_post_types(null, 'object'), function ($e) {
                return $e->hierarchical;
            }));
        }
    }

    public function savePost($post_id)
    {
        global $post, $wpdb;
        if (!in_array(get_post_type(), $this->post_types)) {
            return;
        }

        $path = '/' . implode('/', array_reverse(get_post_ancestors($post_id))) . '/' . $post_id . '/';
        $path = str_replace('//', '/', $path);

        $existing = get_post_meta(get_the_ID(), 'materialized', true);
        if ($existing && $existing == $path) {
            /**
             * Path didn't change.
             */
            return;
        } else if ($existing) {
            /**
             * Update children, if any.
             */
            $sQuery = sprintf('UPDATE %s SET meta_value = REPLACE(meta_value, "%s", "%s") WHERE meta_key = "%s" and meta_value LIKE "%s%%" AND post_id <> %d',
                $wpdb->postmeta,
                $existing,
                $path,
                'materialized',
                $path,
                $post_id
            );
            $wpdb->query($sQuery);
        }

        add_post_meta($post_id, 'materialized', $path, true) || update_post_meta($post_id, 'materialized', $path);
    }


    /**
     * Handle search parameters.
     * @param $q
     * @return mixed
     */
    public function preGetPosts(&$q)
    {
        global $wpdb;
        /**
         * Bail for common reasons.
         **/
        if (
        is_admin()
        ) {
            return $q;
        }


        $path = $q->get($this->search_field);
        if (!$path && !array_key_exists($this->search_field, $_REQUEST)) {
            return $q;
        }

        // Start cleaning
        if (is_numeric($path)) {
            $path = [$path];
        } elseif (is_array($path)) {
        } else if (is_string($path)) {
            $path = preg_split('/\D/', $path);
        } else {
            $path = [];
        }

//        print_r($path);

        // Clean it up.

        if (array_key_exists($this->search_field, $_REQUEST)) {
            $path = array_merge((array)$path, preg_split('/\D/', $_REQUEST[$this->search_field]));
        }
        $path = array_unique($path);
        $path = array_filter($path, 'is_numeric');
        $path = array_flip($path);

        // Get paths, create if needed.
        // This was added as a quick tool to add our debug path.
        foreach ($path as $k => &$v) {
            $v = get_post_meta($k, 'materialized', true);
            // Quick overwrite.
            if (!$v) {
                $temp = '/' . implode('/', array_reverse(get_post_ancestors($k))) . '/' . $k . '/';
                $temp = str_replace('//', '/', $temp);
                add_post_meta($k, 'materialized', $temp, true) || update_post_meta($k, 'materialized', $temp);
            }
        }

        $path = array_filter($path);

        // Remove post_parent__in now.
        $q->query_vars['post_parent__in'] = [];

        // Get existing meta query.
        $meta_query = $q->get('meta_query');
        if (!$meta_query) {
            $meta_query = [];
        }
        // add our new meta_query data
        $guts = array_values(array_map(function ($e) {
            return [
                'key' => 'materialized',
                'value' => $e,
                'compare' => 'LIKE',
            ];
        }, $path));
        $guts['relation'] = 'OR';
        $meta_query[] = $guts;
        $q->set('meta_query', $meta_query);
    }
}

new Plugin();
