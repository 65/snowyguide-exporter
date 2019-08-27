<?php
/*
Plugin Name: Snowy Guide Exporter
Plugin URI: https://www.sixfive.com.au/
Description: Provides custom exporting functionality for the site
Version: 1.0.0
Author: SixFive
Author URI: https://www.sixfive.com.au/
*/

function cooma_create_location_taxo() {
    $labels = array(
        'name'                       => 'Locations',
        'singular_name'              => 'Location',
        'search_items'               => 'Search Locations',
        'popular_items'              => 'Popular Locations',
        'all_items'                  => 'All Locations',
        'parent_item'                => null,
        'parent_item_colon'          => null,
        'edit_item'                  => 'Edit Location',
        'update_item'                => 'Update Location',
        'add_new_item'               => 'Add New Location',
        'new_item_name'              => 'New Location Name',
        'separate_items_with_commas' => 'Separate locations with commas',
        'add_or_remove_items'        => 'Add or remove locations',
        'choose_from_most_used'      => 'Choose from the most used locations',
        'not_found'                  => 'No locations found.',
        'menu_name'                  => 'Locations'
    );

    register_taxonomy(
        'event-location',
        ['event', 'accommodation', 'coffee_food_wine'],
        array(
            'labels'       => $labels,
            'hierarchical' => true,
            'query_var'    => true
        )
    );
}
add_action('init', 'cooma_create_location_taxo');

function em_ical_fz(){
    if (!empty($_GET['ical'])) {
        if (empty($_GET['debug'])) {
            header('Content-type: text/calendar; charset=utf-8');
            header('Content-Disposition: inline; filename="events.ics"');
        }

        include 'icalfz.php';
        die();
    }
}
add_action('init', 'em_ical_fz');
remove_action('init', 'em_ical');

function cooma_get_business_json(WP_REST_Request $request) {
    $data = [];
    $args = [
        'post_type'      => ['accommodation', 'coffee_food_wine'],
        'post_status'    => 'publish',
        'posts_per_page' => -1
    ];

    $cats = $request->get_param('cats');

    if ($cats) {
        $cats = explode(',', $cats);
        $cats = array_map('trim', $cats);

        $args['tax_query'] = [
            [
                'taxonomy' => 'accommodation_types',
                'field'    => 'slug',
                'terms'    => $cats
            ],
            [
                'taxonomy' => 'cfw_types',
                'field'    => 'slug',
                'terms'    => $cats
            ],
            'relation' => 'OR'
        ];
    }

    $businesses = new WP_Query($args);

    if ($businesses->have_posts()) {
        while ($businesses->have_posts()) {
            $businesses->the_post();

            if ('accommodation' == get_post_type()) {
                $key_website          = 'accom_website';
                $key_address          = 'accom_address';
                $key_phone            = 'accom_phone';
                $key_additional_image = 'accom_additional_image';
                $key_taxonomy         = 'accommodation_types';
            } else {
                $key_website          = 'cfw_website';
                $key_address          = 'cfw_address';
                $key_phone            = 'cfw_phone';
                $key_additional_image = 'cfw_additional_image';
                $key_taxonomy         = 'cfw_types';
            }

            // Query terms and address
            $address = get_field($key_address);
            $terms   = wp_get_post_terms(get_the_ID(), $key_taxonomy, ['fields' => 'names']);

            if (is_wp_error($terms)) {
                $terms = [];
            }

            // Locations
            $locations = wp_get_post_terms(get_the_ID(), 'event-location', ['fields' => 'names']);

            if (is_wp_error($locations)) {
                $locations = [];
            }

            // Images
            $images = [];

            if (has_post_thumbnail()) {
                $img = wp_get_attachment_image_src(get_post_thumbnail_id(), 'full');
                $images[] = $img[0];
            }

            if (get_field('pp_banner_image')) {
                $img = get_field('pp_banner_image');
                $images[] = $img['url'];
            }

            if (get_field($key_additional_image)) {
                $img = get_field($key_additional_image);
                $images[] = $img['url'];
            }

            $data[] = [
                'listingid'     => get_the_ID(),
                'name'          => get_the_title(),
                'openingHours'  => null,
                'phone'         => (get_field($key_phone) ? trim(get_field($key_phone)) : null),
                'email'         => null,
                'website'       => (get_field($key_website) ? trim(get_field($key_website)) : null),
                'address'       => ($address ? trim($address['address']) : null),
                'city'          => null,
                'state'         => null,
                'postcode'      => null,
                'latitude'      => ($address ? trim($address['lat']) : null),
                'longitude'     => ($address ? trim($address['lng']) : null),
                'locations'      => $locations,
                'dt_lastupdate' => get_the_modified_date(DATE_ISO8601),
                'categories'    => $terms,
                'images'        => $images
            ];
        }
    }
    return $data;
}

add_action('rest_api_init', function () {
    register_rest_route('business', '/list/', array(
        'methods'  => 'GET',
        'callback' => 'cooma_get_business_json'
    ));
});

function cooma_ping_snowyguide() {
    wp_remote_get('http://api.snowyguide.com/ping?url=' . home_url('/wp-json/business/list'));
}
add_action('save_post_accommodation', 'cooma_ping_snowyguide');
add_action('save_post_coffee_food_wine', 'cooma_ping_snowyguide');
