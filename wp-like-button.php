<?php
/*
Plugin Name:  WP Like button
Plugin URI:   https://github.com/anildemir/wp-like-button
Description:  Postları beğenme eklemize yarayan bir uygulama 
Version:      0.0.1
Author:       Ege ORHAN
License:      GPLv3
License URI:  http://www.gnu.org/licenses/gpl-3.0.html





*/


function wplb_styles()
{
    wp_register_style("wp-like-button-style-file", plugin_dir_url(__FILE__) . "/css/wp-like-button.css");
    wp_enqueue_style("wp-like-button-style-file");

    wp_register_style("like-font-awesome", "//maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css");
    wp_enqueue_style("like-font-awesome");
}

function wplb_scripts()
{
    wp_register_script("wp-like-button-script-file", plugin_dir_url(__FILE__) . "/js/wp-like-button.js", array(
        'jquery'
    ));
    wp_enqueue_script("wp-like-button-script-file");
    wp_localize_script('wp-like-button-script-file', 'ajax_var', array(
        'url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ajax-nonce')
    ));
}

add_action("wp_enqueue_scripts", "wplb_styles");
add_action("wp_enqueue_scripts", "wplb_scripts");

/*
 * Setting up a PHP function to handle Ajax
 * Currently, the plugin does not support likes from anonymous users
 */

// add_action('wp_ajax_nopriv_like_post', 'like_post'); // For non-logged-in users
add_action('wp_ajax_wplb_like_post', 'wplb_like_post'); // For logged in users

/*
 * like/Unlike a post
 * Hooked into wp_ajax_ above to save post IDs when button clicked.
 */

function wplb_like_post()
{

    // Security measures for the ajax call

    $nonce = $_POST['_wpnonce'];
    if (!wp_verify_nonce($nonce, 'ajax-nonce'))
        die("Security check has not passed.");

    if (isset($_POST['wplb_like_post'])) {

        // Get the post ID of the clicked button's post and the current user's ID

        $post_id = $_POST['post_id'];
        $user_id = get_current_user_id();

        // Get the tags associated to the post and their like counts of the post associa

        $tag_like_counts = wplb_get_tag_likes($post_id);

        // Get the count of likes for the particular post

        $post_like_count = get_post_meta($post_id, "_like_count", true);

        // Get the users who liked the post

        $postmetadata_userIDs = get_post_meta($post_id, "_user_liked");

        $users_liked = array();

        if (count($postmetadata_userIDs) != 0) {
            $users_liked = $postmetadata_userIDs[0];
        }

        if (!is_array($users_liked))
            $users_liked = array();

        $users_liked['User_ID-' . $user_id] = $user_id;

        if (!wplb_already_liked($post_id)) {

            // like

            update_post_meta($post_id, "_user_liked", $users_liked);
            update_post_meta($post_id, "_like_count", ++$post_like_count);

            foreach ($tag_like_counts as $key => $value) {
                update_term_meta($key, "_like_count", ++$value);
            }
            $response['count']   = $post_like_count;
            $response['message'] = "You liked this! " . '<i class="fa fa-heart"></i>';
        }

        else {

            // Unlike

            $uid_key = array_search($user_id, $liked_users); // find the key
            unset($liked_users[$uid_key]); // remove from array

            update_post_meta($post_id, "_user_liked", $liked_users); // Remove user ID from post meta
            update_post_meta($post_id, "_like_count", --$post_like_count); // -1 count post meta

            foreach ($tag_like_counts as $key => $value) {

                update_term_meta($key, "_like_count", --$value);

            }
            $response['count']   = $post_like_count;
            $response['message'] = "like this! " . '<i class="fa fa-heart"></i>';

        }

        wp_send_json($response);
    }
}

/*
/ Function to display the like button on the front-end below every post
*/

function wplb_display_like_button($post_id)
{
    // Total counts for the post

    $like_count = get_post_meta($post_id, "_like_count", true);
    $count      = (empty($like_count) || $like_count == "0") ? ' Nobody has liked this yet.' : $like_count;

    // Prepare button html

    if (!wplb_already_liked($post_id)) {
        $html = '<div class="wplb-wrapper"><a href="#" data-post_id="' . $post_id . '">like this! <i class="fa fa-heart"></i></a><span>' . $count . ' </span></div>';
    } else {
        $html = '<div class="wplb-wrapper wplb-liked"><a href="#" data-post_id="' . $post_id . '">You liked this! <i class="fa fa-heart"></i></a><span> ' . $count . '</span></div>';
    }

    return $html;
}

/*
 *   Adding the button to each post if the user is logged in
 *   and the post type is "post"
 */

function wplb_add_like_button($content)
{
    return (is_user_logged_in() && get_post_type() == post) ? $content . wplb_display_like_button(get_the_ID()) : $content;
}

add_filter("the_content", "wplb_add_like_button");

/*
 * Function to check whether the user who clicks the like button already liked the post
 */

function wplb_already_liked($post_id)
{

    $user_id              = get_current_user_id();
    $postmetadata_userIDs = get_post_meta($post_id, "_user_liked");
    $users_liked          = array();

    if (count($postmetadata_userIDs) != 0) {
        $users_liked = $postmetadata_userIDs[0];
    }
    if (!is_array($users_liked))
        $users_liked = array();

    if (in_array($user_id, $users_liked)) {
        return true;
    } else {
        return false;
    }
}


/*
* When a tag is added to or removed from a post
* These functions update the tag likes according to their current post's likes
*/

function wplb_update_after_tag_add($object_id, $tt_id, $taxonomy)
{
    if ($taxonomy == 'post_tag') {
        $post_like_count = get_post_meta($object_id, "_like_count", true);
        $tag_like_count = get_term_meta($tt_id, "_like_count", true);
        $tag_like_count += $post_like_count;
        update_term_meta($tt_id, "_like_count", $tag_like_count);
    }
}

function wplb_update_after_tag_remove($object_id, $tt_id, $taxonomy)
{
    if ($taxonomy == 'post_tag') {
        $post_like_count = get_post_meta($object_id, "_like_count", true);
        $tag_like_count = get_term_meta($tt_id, "_like_count", true);
        $tag_like_count -= $post_like_count;
        update_term_meta($tt_id, "_like_count", $tag_like_count);
    }
}

add_action('added_term_relationship', 'wplb_update_after_tag_add', 10, 3);
add_action('delete_term_relationships', 'wplb_update_after_tag_remove', 10, 3);

/*
 * Registering the shortcode
 */

add_shortcode('wplb-tag-likes', 'wplb_tag_likes_shortcode');

/*
 * Registering the widget
 */

function wplb_register_widgets()
{
    register_widget('WP_like_Button_Widget');
}

add_action('widgets_init', 'wplb_register_widgets');

/*
 * The function that returns an array of tags and their like counts
 * When the function is called without parameters
 * It returns all the tags with their like counts
 * Else it gets all the tags associated to the given post
 */

function wplb_get_tag_likes($post_id = -1)
{

    $tags = ($post_id == -1) ? get_tags() : wp_get_post_tags($post_id);

    $tag_like_counts = array();

    foreach ($tags as $tag) {

        $like_count = get_term_meta($tag->term_id, "_like_count", true);
        if (!$like_count)
            $like_count = 0;
        $tag_like_counts[$tag->term_id] = $like_count;

    }
    arsort($tag_like_counts); // Descending order, most liked at the top
    return $tag_like_counts;

}
