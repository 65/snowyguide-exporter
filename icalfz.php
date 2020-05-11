<?php

global $wpdb;

$events_table    = EM_EVENTS_TABLE;
$locations_table = EM_LOCATIONS_TABLE;

/*$sql = "
    SELECT * FROM $events_table
    LEFT JOIN $locations_table ON {$locations_table}.location_id={$events_table}.location_id WHERE
    event_status = 1 AND
    (recurrence !=1 OR recurrence IS NULL) AND
    (
        event_start_date >= CAST('" . date('Y-m-d') . "' AS DATE)
        OR
        (
            event_end_date >= CAST('" . date('Y-m-d') . "' AS DATE)
            AND
            event_end_date != '0000-00-00'
            AND
            event_end_date IS NOT NULL
        )
    ) AND
    {{where_postids}}
    GROUP BY $events_table.post_id ORDER BY event_start_date ASC
    LIMIT 0,50";*/
$sql = "
    SELECT * FROM $events_table
    LEFT JOIN $locations_table ON {$locations_table}.location_id={$events_table}.location_id WHERE
    event_status = 1 AND
    
    (
        event_start_date >= CAST('" . date('Y-m-d') . "' AS DATE)
        OR
        (
            event_end_date >= CAST('" . date('Y-m-d') . "' AS DATE)
            AND
            event_end_date != '0000-00-00'
            AND
            event_end_date IS NOT NULL
        )
    ) AND
    {{where_postids}}
    GROUP BY $events_table.post_id ORDER BY event_start_date ASC
    LIMIT 0,100";

if (!empty($_GET['event-cat']) || !empty($_GET['location'])) {

    $args = [
        'post_type'      => 'event',
        'posts_per_page' => -1,
        'tax_query'      => []
    ];

    if (!empty($_GET['event-cat'])) {
        $categories = explode(',', $_GET['event-cat']);
        $categories = array_map('trim', $categories);

        $args['tax_query'][] = [
            'taxonomy' => 'event-categories',
            'field'    => 'slug',
            'terms'    => $categories
        ];
    }

    if (!empty($_GET['location'])) {
        $locations = explode(',', $_GET['location']);
        $locations = array_map('trim', $locations);

        $args['tax_query'][] = [
            'taxonomy' => 'event-location',
            'field'    => 'slug',
            'terms'    => $locations
        ];
    }

    $post_ids = new WP_Query($args);

    if ($post_ids->found_posts) {
        $post_ids = wp_list_pluck($post_ids->posts, 'ID');
        $where_postids = "({$events_table}.post_id=" . implode(" OR {$events_table}.post_id=", $post_ids) . ")";
    } else {
        $where_postids = '1=0';
    }
} else {
    $where_postids = '1=1';
}
$sql = str_replace('{{where_postids}}', $where_postids, $sql);

$results   = $wpdb->get_results($sql, ARRAY_A);
$fz_events = array();

foreach($results as $result){
    $locations = [];
    /*echo '<pre>';
    print_r($result);
    echo '</pre>';*/
    if($result['recurrence'] == 1) $_slug = 'event-recurring';
    else $_slug = 'event';


    $queried_post = get_page_by_path($result['event_slug'], OBJECT, $_slug);
    if($queried_post != null) {
        // Locations
        $locations = wp_get_post_terms($queried_post->ID, 'event-location', ['fields' => 'names']);

        if (is_wp_error($locations)) {
            $locations = [];
        }
    }

    if(count($locations) < 1) continue;

    $fz_events[$result['event_id']] = new EM_Event($result['event_id']);
}
/*var_dump(count($fz_events));
echo '<br>';
foreach ($fz_events as $fz_event) {
    echo $fz_event->event_id.'             '.$fz_event->post_id."<br>";
}
die();*/

//define and clean up formats for display
$summary_format = str_replace ( ">", "&gt;", str_replace ( "<", "&lt;", get_option ( 'dbem_ical_description_format' ) ) );
$description_format = str_replace ( ">", "&gt;", str_replace ( "<", "&lt;", get_option ( 'dbem_ical_real_description_format') ) );
$location_format = str_replace ( ">", "&gt;", str_replace ( "<", "&lt;", get_option ( 'dbem_ical_location_format' ) ) );

//figure out limits
$ical_limit = get_option('dbem_ical_limit');
$page_limit = $ical_limit > 50 || !$ical_limit ? 50:$ical_limit; //set a limit of 50 to output at a time, unless overall limit is lower
//get passed on $args and merge with defaults
$args = !empty($args) ? $args:array(); /* @var $args array */
$args = array_merge(array('limit'=>$page_limit, 'page'=>'1', 'owner'=>false, 'orderby'=>'event_start_date', 'scope' => get_option('dbem_ical_scope') ), $args);
$args = apply_filters('em_calendar_template_args',$args);
//get first round of events to show, we'll start adding more via the while loop
$EM_Events = $fz_events;

//calendar header
$output = "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//wp-events-plugin.com//".EM_VERSION."//EN";
echo preg_replace("/([^\r])\n/", "$1\r\n", $output);

//loop through events
foreach ( $EM_Events as $EM_Event ) {
    $timezone = get_option('timezone_string');
    //calculate the times along with timezone offsets
    if($EM_Event->event_all_day){
        $dateStart  = ';VALUE=DATE:'.date('Ymd',$EM_Event->start); //all day
        $dateEnd    = ';VALUE=DATE:'.date('Ymd',$EM_Event->end + 86400); //add one day
    }else{
        //$dateStart  = ':'.get_gmt_from_date(date('Y-m-d H:i:s', $EM_Event->start), 'Ymd\THis\Z');
        //$dateEnd = ':'.get_gmt_from_date(date('Y-m-d H:i:s', $EM_Event->end), 'Ymd\THis\Z');

        $dateStart = sprintf(';TZID=%s:%s', $timezone, date('Ymd\THis', $EM_Event->start));
        $dateEnd = sprintf(';TZID=%s:%s', $timezone, date('Ymd\THis', $EM_Event->end));
    }
    if( !empty($EM_Event->event_date_modified) && $EM_Event->event_date_modified != '0000-00-00 00:00:00' ){
        $dateModified =  get_gmt_from_date($EM_Event->event_date_modified, 'Ymd\THis\Z');
    }else{
        $dateModified = get_gmt_from_date($EM_Event->post_modified, 'Ymd\THis\Z');
    }

    //formats
    $summary = $EM_Event->output($summary_format,'ical');
    $description = $EM_Event->output($description_format,'ical');
    //$location = $EM_Event->output($location_format, 'ical');

    $location = $geo = $apple_geo = $apple_location = $apple_location_title = $categories = false;

    if( $EM_Event->location_id ){
        $location = $EM_Event->output($location_format, 'ical');
        if( $EM_Event->get_location()->location_latitude || $EM_Event->get_location()->location_longitude ){
            $geo = $EM_Event->get_location()->location_latitude.";".$EM_Event->get_location()->location_longitude;
        }
        $apple_location = $EM_Event->output('#_LOCATIONFULLLINE, #_LOCATIONCOUNTRY', 'ical');
        $apple_location_title = $EM_Event->get_location()->location_name;
        $apple_geo = !empty($geo) ? $geo:'0,0';
    }

    // Location
    /*$locs = wp_get_post_terms($EM_Event->post_id, 'event-location');

    if (!empty($locs)) {
        $location = wp_list_pluck($locs, 'name');
        $location = implode(', ', $location);
    } else {
        $location = '';
    }*/

    // Categories
    $cats = wp_get_post_terms($EM_Event->post_id, 'event-categories');

    if (!empty($cats)) {
        $categories = wp_list_pluck($cats, 'name');
        $categories = implode(', ', $categories);
    } else {
        $categories = '';
    }

    // featured images
    if (has_post_thumbnail($EM_Event->post_id)) {
        $featured_image = 'X-WP-IMAGES-URL:';

        $_image = wp_get_attachment_image_src(get_post_thumbnail_id($EM_Event->post_id), 'full');
        $featured_image .= $_image[0];

        /*foreach (['thumbnail', 'medium', 'large'] as $size) {
            $_image = wp_get_attachment_image_src(get_post_thumbnail_id($EM_Event->post_id), $size);
            $featured_image .= sprintf('1,%s;%s;%s;%s;', $size, $_image[0], $_image[1], $_image[2]);
        }*/

        //$thumbnail_id        = get_post_thumbnail_id($EM_Event->post_id);
        //$thumbnail_url       = wp_get_attachment_url($thumbnail_id);
        //$thumbnail_mime_type = get_post_mime_type($thumbnail_id);

        //$featured_image = sprintf("ATTACH;FMTTYPE=%s:%s", $thumbnail_mime_type, $thumbnail_url);
    } else {
        $featured_image = false;
    }

    //create a UID
    /*$UID = sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        // 32 bits for "time_low"
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
        // 16 bits for "time_mid"
        mt_rand( 0, 0xffff ),
        // 16 bits for "time_hi_and_version",
        // four most significant bits holds version number 4
        mt_rand( 0, 0x0fff ) | 0x4000,
        // 16 bits, 8 bits for "clk_seq_hi_res",
        // 8 bits for "clk_seq_low",
        // two most significant bits holds zero and one for variant DCE1.1
        mt_rand( 0, 0x3fff ) | 0x8000,
        // 48 bits for "node"
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
    );*/
    $UID = 'vc-'.$EM_Event->post_id;

//output ical item
$output = "
BEGIN:VEVENT
UID:{$UID}
DTSTART{$dateStart}
DTEND{$dateEnd}
DTSTAMP:{$dateModified}
SUMMARY:{$summary}";
if( $description ){
    $output .= "
DESCRIPTION:{$description}";
}
$output .= "
CATEGORIES:{$categories}";
if( $featured_image ){
    $output .= "
{$featured_image}";
}
//Location if there is one
if( $location ){
    $output .= "
LOCATION:{$location}";
    //geo coordinates if they exist
    if( $geo ){
    $output .= "
GEO:{$geo}";
    }
    //create apple-compatible feature for locations
    $output .= "
X-APPLE-STRUCTURED-LOCATION;VALUE=URI;X-ADDRESS={$apple_location};X-APPLE-RADIUS=100;X-TITLE={$apple_location_title}:geo:{$apple_geo}";
}
$output .= "
URL:{$EM_Event->get_permalink()}
END:VEVENT";

        //clean up new lines, rinse and repeat
        echo preg_replace("/([^\r])\n/", "$1\r\n", $output);
    }

//calendar footer
$output = "
END:VCALENDAR";
echo preg_replace("/([^\r])\n/", "$1\r\n", $output);
