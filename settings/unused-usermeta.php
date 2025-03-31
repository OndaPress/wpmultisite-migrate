<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get list of unused metadata keys that can be safely deleted
 *
 * @return array Array of unused metadata keys
 */
function opmsm_get_unusedUserMetaKeys() {
    return array(
        // User preferences and settings
        'comment_shortcuts',
        'show_admin_bar_front',
        'dismissed_wp_pointers',
        'default_password_nag',
        'dashboard_quick_press_last_post_id',
        'user-settings',
        'user-settings-time',
        'persisted_preferences',
        'upload_per_page',
        'edit_post_per_page',
        'enable_custom_fields',
        
        // Dashboard and UI preferences
        'closedpostboxes_post',
        'metaboxhidden_post',
        'closedpostboxes_dashboard',
        'metaboxhidden_dashboard',
        'nav_menu_recently_edited',
        'managenav-menuscolumnshidden',
        'metaboxhidden_nav-menus',
        'closedpostboxes_page',
        'metaboxhidden_page',
        'meta-box-order_dashboard',
        'media_library_mode',
        'screen_layout_page',
        'meta-box-order_page',
        
        // Plugin-specific settings
        '_fl_builder_launched',
        'jetpack_tracks_anon_id',
        'jetpack_tracks_wpcom_id',
        'sb_instagram_ignore_notice_2016',
        '_yoast_wpseo_profile_updated',
        '_yoast_wpseo_introductions',
        '_yoast_alerts_dismissed',
        'wpseo_title',
        'wpseo_metadesc',
        'wpseo_metakey',
        'wpseo_content_analysis_disable',
        'wpseo_keyword_analysis_disable',
        'wpseo_inclusive_language_analysis_disable',
        
        // Social media links
        'twitter',
        'googleplus',
        'stumbleupon',
        'wordpress',
        'dribbble',
        'vimeo',
        'rss',
        'deviantart',
        'skype',
        'picassa',
        'flickr',
        'blogger',
        'spotify',
        'delicious',
        'behance',
        'digg',
        'evernote',
        'forrst',
        'grooveshark',
        'lastfm',
        'mail-1',
        'path',
        'paypal',
        'reddit',
        'share',
        'stackoverflow',
        'steam',
        'vk',
        'windows',
        'yahoo',
        
        // Other settings
        'syntax_highlighting',
        'session_tokens',
        'community-events-location',
        '_new_email',
        'manageuploadcolumnshidden',
        
        // Dynamic patterns
        'closedpostboxes_',
        'metaboxhidden_',
        'meta-box-order_',
        
        // Legacy capabilities and user levels (will be handled separately)
        '_capabilities',
        '_user_level',
    );
} 