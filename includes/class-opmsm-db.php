<?php
if (!defined('ABSPATH')) {
    exit;
}

class OPMSM_DB {
    private static $instance = null;
    private $unisites_table;
    private $users_table;
    private $charset_collate;

    private function __construct() {
        global $wpdb;
        
        $this->unisites_table = $wpdb->base_prefix . 'opmsm_unisites';
        $this->users_table = $wpdb->base_prefix . 'opmsm_user_migrations';
        $this->charset_collate = $wpdb->get_charset_collate();

        // Hook into site creation and initialization
        add_action('wp_initialize_site', array($this, 'syncSites'), 100);
        add_action('admin_init', array($this, 'maybe_syncSites'));
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Sync sites if needed
     */
    public function maybe_syncSites() {
        // Only sync if we're in the admin area and the tables exist
        if (!is_admin()) {
            return;
        }

        // Don't sync during AJAX requests
        if (wp_doing_ajax()) {
            return;
        }

        // Don't sync during settings save
        if (isset($_POST['opmsm_save_settings'])) {
            return;
        }

        global $wpdb;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->unisites_table}'");
        
        if ($table_exists) {
            $this->syncSites();
        }
    }

    /**
     * Create required database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Main unisites table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->unisites_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            site_id bigint(20) NOT NULL,
            db_name varchar(255) NOT NULL,
            db_prefix varchar(255) NOT NULL,
            migrate_extra_tables tinyint(1) NOT NULL DEFAULT 0,
            clean_unused_data tinyint(1) NOT NULL DEFAULT 0,
            migration_status varchar(20) NOT NULL DEFAULT 'pending',
            current_operation text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY site_id (site_id)
        ) $charset_collate;";

        // User migrations table
        $sql .= "CREATE TABLE IF NOT EXISTS {$this->users_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            site_id bigint(20) NOT NULL,
            wp_sitename varchar(255) NOT NULL,
            old_user_id bigint(20) NOT NULL,
            new_user_id bigint(20) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY site_id (site_id),
            KEY old_user_id (old_user_id),
            KEY new_user_id (new_user_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Sync sites from network
     */
    public function syncSites() {
        global $wpdb;
        
        // Get all sites from network
        $sites = $wpdb->get_results("SELECT blog_id, domain, path FROM {$wpdb->blogs} where blog_id != 1");
        
        foreach ($sites as $site) {
            // Check if site exists in our table
            $existing = $this->getUnisiteBySiteId($site->blog_id);
            
            if (!$existing) {
                // Get site details
                $site_details = get_blog_details($site->blog_id);
                $db_name = $wpdb->dbname;
                $db_prefix = $wpdb->get_blog_prefix($site->blog_id);
                
                // Add new site
                $this->addUnisite(array(
                    'site_id' => $site->blog_id,
                    'db_name' => $db_name,
                    'db_prefix' => $db_prefix,
                    'migrate_extra_tables' => 0,
                    'clean_unused_data' => 0,
                    'migration_status' => 'pending'
                ));
            }
        }
    }

    /**
     * Get all unisites
     */
    public function getAllUnisites() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->unisites_table}");
    }

    /**
     * Get unisite by ID
     */
    public function getUnisite($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->unisites_table} WHERE id = %d",
            $id
        ));
    }

    /**
     * Get unisite by site ID
     */
    public function getUnisiteBySiteId($site_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->unisites_table} WHERE site_id = %d",
            $site_id
        ));
    }

    /**
     * Add new unisite
     */
    public function addUnisite($data) {
        global $wpdb;
        
        // Ensure we're not generating any output
        ob_start();
        $result = $wpdb->insert($this->unisites_table, $data);
        ob_end_clean();
        
        return $result;
    }

    /**
     * Update unisite
     */
    public function updateUnisite($id, $data) {
        global $wpdb;
        
        // Ensure we're not generating any output
        ob_start();
        $result = $wpdb->update(
            $this->unisites_table,
            $data,
            array('id' => $id)
        );
        ob_end_clean();
        
        return $result;
    }

    /**
     * Delete unisite
     */
    public function deleteUnisite($id) {
        global $wpdb;
        return $wpdb->delete($this->unisites_table, array('id' => $id));
    }

    /**
     * Add user migration record
     */
    public function addUserMigration($site_id, $old_user_id, $new_user_id) {
        global $wpdb;
        $site = get_blog_details($site_id);
        
        return $wpdb->insert(
            $this->users_table,
            array(
                'site_id' => $site_id,
                'wp_sitename' => $site->blogname,
                'old_user_id' => $old_user_id,
                'new_user_id' => $new_user_id
            )
        );
    }

    /**
     * Get user migration record
     */
    public function getUserMigration($site_id, $old_user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->users_table} WHERE site_id = %d AND old_user_id = %d",
            $site_id,
            $old_user_id
        ));
    }

    /**
     * Get all user migrations for a site
     */
    public function getUserMigrations($site_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->users_table} WHERE site_id = %d",
            $site_id
        ));
    }
} 