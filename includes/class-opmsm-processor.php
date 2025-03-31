<?php
if (!defined('ABSPATH')) {
    exit;
}

class OPMSM_Processor
{
    private $db;
    private $source_db;
    private $targetSiteID;
    private $sourcePrefix;
    private $targetPrefix;
    private $migrateExtraTables;
    private $cleanUnusedData;
    private $oldDBName;
    private $unisite;
    private $unusedMetaKeys;
    private $unusedPostMetaKeys;
    private $unusedOptions;

    public function __construct($unisite)
    {
        $this->db = OPMSM_DB::get_instance();
        $this->unisite = $unisite;
        $this->targetSiteID = $unisite->site_id;
        $this->sourcePrefix = $unisite->db_prefix;
        $this->targetPrefix = $this->getTargetPrefix();
        $this->migrateExtraTables = $unisite->migrate_extra_tables;
        $this->cleanUnusedData = $unisite->clean_unused_data;
        $this->oldDBName = $unisite->db_name;
        $this->unusedMetaKeys = $this->getUnusedUserMetaKeys();
        $this->unusedPostMetaKeys = $this->getUnusedPostMetaKeys();
        $this->unusedOptions = $this->getUnusedOptions();
        switch_to_blog($unisite->site_id);
        // Connect to source database
        $this->source_db = new wpdb(
            DB_USER,
            DB_PASSWORD,
            $this->oldDBName,
            DB_HOST
        );
        $this->source_db->set_prefix($this->sourcePrefix);
    }

    /**
     * Get the target table prefix for the current site
     */
    private function getTargetPrefix()
    {
        global $wpdb;
        return $wpdb->get_blog_prefix($this->targetSiteID);
    }

    /**
     * Get list of unused metadata keys
     *
     * @return array Array of unused metadata keys
     */
    private function getUnusedUserMetaKeys()
    {
        // Load settings file only when needed
        if (!function_exists('opmsm_get_unusedUserMetaKeys')) {
            require_once OPMSM_PLUGIN_DIR . 'settings/unused-usermeta.php';
        }
        return opmsm_get_unusedUserMetaKeys();
    }

    /**
     * Get list of unused post metadata keys
     *
     * @return array Array of unused post metadata keys
     */
    private function getUnusedPostMetaKeys()
    {
        $file = OPMSM_PLUGIN_DIR . 'settings/unused-postmeta.php';
        if (!file_exists($file)) {
            return array(); // Return empty array if file doesn't exist
        }

        // Load settings file only when needed
        if (!function_exists('opmsm_get_unusedPostMetaKeys')) {
            require_once $file;
        }
        return opmsm_get_unusedPostMetaKeys();
    }

    /**
     * Clean up posts and postmeta
     */
    private function cleanupPosts()
    {
        WP_CLI::log("Cleaning up posts and postmeta");
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->terms}");
        $wpdb->query("DELETE FROM {$wpdb->term_taxonomy}");
        $wpdb->query("DELETE FROM {$wpdb->term_relationships}");
        return true;
    }

    /**
     * Clean up options
     * 
     * @param bool $force Whether to force cleanup even in production
     * @return bool Whether cleanup was performed
     */
    private function cleanupOptions($force = false)
    {
        if (!$force && defined('WP_ENV') && WP_ENV === 'production') {
            return false;
        }

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name NOT IN ('siteurl', 'home', 'blogname', 'admin_email')");
        return true;
    }

    /**
     * Clean up extra tables
     * 
     * @param bool $force Whether to force cleanup even in production
     * @return bool Whether cleanup was performed
     */
    private function cleanupExtraTables($force = false)
    {
        if (!$force && defined('WP_ENV') && WP_ENV === 'production') {
            return false;
        }

        if (!$this->migrateExtraTables) {
            return false;
        }

        global $wpdb;
        $tables = $wpdb->get_results("SHOW TABLES LIKE '{$this->targetPrefix}%'");
        foreach ($tables as $table) {
            $table_name = array_values((array) $table)[0];

            // Skip WordPress core tables
            $core_tables = array(
                'posts',
                'postmeta',
                'comments',
                'commentmeta',
                'terms',
                'termmeta',
                'term_taxonomy',
                'term_relationships',
                'options',
                'users',
                'usermeta',
                'links'
            );

            if (!in_array(str_replace($this->targetPrefix, '', $table_name), $core_tables)) {
                $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
            }
        }
        return true;
    }

    /**
     * Migrate users from source to target
     */
    public function migrateUsers($cleanup = true, $force = false)
    {
        WP_CLI::log("Now we're going to migrate users");
        global $wpdb;

        // Get users from old database
        $old_users = $wpdb->get_results("
            SELECT ID, user_login, user_pass, user_nicename, user_email, user_url, 
                   user_registered, user_activation_key, user_status, display_name
            FROM {$this->oldDBName}.{$this->sourcePrefix}users
        ");

        if (empty($old_users)) {
            return 0;
        }

        $migrated_count = 0;

        // Update status at start
        $this->db->updateUnisite($this->unisite->id, array(
            'migration_status' => 'in_progress',
            'current_operation' => 'Starting user migration'
        ));

        WP_CLI::log(sprintf('Starting user migration. Total users to process: %d', count($old_users)));
        $progress = \WP_CLI\Utils\make_progress_bar('Migrating users', count($old_users));

        foreach ($old_users as $old_user) {
            // Check if user already exists
            $existing_user = get_user_by('email', $old_user->user_login);
            if ($existing_user) {
                $new_user_id = $existing_user->ID;
            } else {
                // Insert user
                $wpdb->insert(
                    $wpdb->users,
                    array(
                        'user_login' => $old_user->user_login,
                        'user_pass' => $old_user->user_pass,
                        'user_nicename' => $old_user->user_nicename,
                        'user_email' => $old_user->user_email,
                        'user_url' => $old_user->user_url,
                        'user_registered' => $old_user->user_registered,
                        'user_activation_key' => $old_user->user_activation_key,
                        'user_status' => $old_user->user_status,
                        'display_name' => $old_user->display_name
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
                );

                $new_user_id = $wpdb->insert_id;
            }

            // Get user meta from old database
            $sqlStatement = "SELECT meta_key, meta_value 
                            FROM {$this->oldDBName}.{$this->sourcePrefix}usermeta 
                            WHERE user_id = %d";

            foreach ($this->unusedMetaKeys as $key) {
                if (str_contains($key, '%')) {
                    $sqlStatement .= " AND meta_key NOT LIKE '" . $key . "'";
                } else {
                    $sqlStatement .= " AND meta_key != '" . $key . "'";
                }
            }

            $old_meta = $wpdb->get_results($wpdb->prepare($sqlStatement, $old_user->ID));

            foreach ($old_meta as $meta) {
                // Handle capabilities prefix change
                if ($meta->meta_key === $this->sourcePrefix . 'capabilities') {
                    $meta->meta_key = $this->targetPrefix . 'capabilities';
                }

                // Insert user meta (no need for the in_array check anymore since we filtered at DB level)
                $wpdb->insert(
                    $wpdb->usermeta,
                    array(
                        'user_id' => $new_user_id,
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ),
                    array('%d', '%s', '%s')
                );
            }

            // Record user migration
            $this->db->addUserMigration($this->unisite->site_id, $old_user->ID, $new_user_id);
            $migrated_count++;
            $progress->tick();
        }

        $progress->finish();

        // Update status at end
        $this->db->updateUnisite($this->unisite->id, array(
            'migration_status' => 'completed',
            'current_operation' => sprintf('Completed user migration. Total users migrated: %d', $migrated_count)
        ));

        WP_CLI::success(sprintf('Completed user migration. Total users migrated: %d', $migrated_count));

        return $migrated_count;
    }

    /**
     * Migrate options table
     */
    public function migrateOptions($cleanup = true, $force = false)
    {
        WP_CLI::log("Now we're going to migrate options");
        if ($cleanup) {
            $this->cleanupOptions($force);
        }

        $sqlStatement = "SELECT * FROM {$this->sourcePrefix}options WHERE 1=1";

        // Core options that shouldn't be migrated
        $core_options = array('siteurl', 'home', 'blogname', 'admin_email');
        foreach ($core_options as $option) {
            $sqlStatement .= " AND option_name != '" . esc_sql($option) . "'";
        }

        // Add filters for unused options
        foreach ($this->unusedOptions as $key) {
            if (str_contains($key, '%')) {
                $sqlStatement .= " AND option_name NOT LIKE '" . esc_sql($key) . "'";
            } else {
                $sqlStatement .= " AND option_name != '" . esc_sql($key) . "'";
            }
        }

        $options = $this->source_db->get_results($sqlStatement);
        $count = 0;

        // Update status at start
        $this->db->updateUnisite($this->unisite->id, array(
            'migration_status' => 'in_progress',
            'current_operation' => 'Starting options migration'
        ));

        WP_CLI::log(sprintf('Starting options migration. Total options to process: %d', count($options)));
        $progress = \WP_CLI\Utils\make_progress_bar('Migrating options', count($options));

        foreach ($options as $option) {
            // Add site-specific prefix to option name if needed
            $option_name = $option->option_name;
            if (strpos($option_name, $this->sourcePrefix) === 0) {
                $option_name = substr($option_name, strlen($this->sourcePrefix));
            }

            // Update or add option
            update_blog_option($this->targetSiteID, $option_name, maybe_unserialize($option->option_value));
            $count++;
            $progress->tick();
        }

        $progress->finish();

        // Update status at end
        $this->db->updateUnisite($this->unisite->id, array(
            'migration_status' => 'completed',
            'current_operation' => sprintf('Completed options migration. Total options migrated: %d', $count)
        ));

        WP_CLI::success(sprintf('Completed options migration. Total options migrated: %d', $count));
        return $count;
    }

    /**
     * Migrate posts and their taxonomies
     */
    public function migratePosts($cleanup = true, $force = false)
    {


        WP_CLI::log("Post target processing");
        if ($cleanup) {
            $this->cleanupPosts();
        }

        global $wpdb;

        WP_CLI::log("First, we going to alter the posts target table to avoid conflicts");
        // Update invalid post dates in source database
        $this->source_db->query("UPDATE {$wpdb->dbname}.{$wpdb->posts} SET post_date = '1970-01-01 00:00:00' WHERE post_date = '0000-00-00 00:00:00'");
        $this->source_db->query("UPDATE {$wpdb->dbname}.{$wpdb->posts} SET post_date_gmt = '1970-01-01 00:00:00' WHERE post_date_gmt = '0000-00-00 00:00:00'");
        $this->source_db->query("UPDATE {$wpdb->dbname}.{$wpdb->posts} SET post_modified = '1970-01-01 00:00:00' WHERE post_modified = '0000-00-00 00:00:00'");
        $this->source_db->query("UPDATE {$wpdb->dbname}.{$wpdb->posts} SET post_modified_gmt = '1970-01-01 00:00:00' WHERE post_modified_gmt = '0000-00-00 00:00:00'");

        // Change default values for date columns
        $this->source_db->query("ALTER TABLE {$wpdb->dbname}.{$wpdb->posts} ALTER post_date SET DEFAULT '1970-01-01 00:00:00'");
        $this->source_db->query("ALTER TABLE {$wpdb->dbname}.{$wpdb->posts} ALTER post_date_gmt SET DEFAULT '1970-01-01 00:00:00'");
        $this->source_db->query("ALTER TABLE {$wpdb->dbname}.{$wpdb->posts} ALTER post_modified SET DEFAULT '1970-01-01 00:00:00'");
        $this->source_db->query("ALTER TABLE {$wpdb->dbname}.{$wpdb->posts} ALTER post_modified_gmt SET DEFAULT '1970-01-01 00:00:00'");

        

        // Add temporary column for old post author if it doesn't exist
        $columnsPosts = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->dbname}.{$wpdb->posts}  where Field like 'old_post_author'");
        if (is_null($columnsPosts)) {
            WP_CLI::log("Now we're going to use a helper column to migrate the posts");
            $wpdb->query("ALTER TABLE {$wpdb->dbname}.{$wpdb->posts} ADD COLUMN  old_post_author bigint(20) unsigned DEFAULT NULL");
        }
        WP_CLI::log("Now we're going to migrate posts");
        // Direct INSERT SELECT between databases, including old author in temporary column
        $sql = "INSERT INTO {$wpdb->posts} (
ID,
post_author,
post_date,
post_date_gmt,
post_content,
post_title,
post_excerpt,
post_status,
comment_status,
ping_status,
post_name,
to_ping,
pinged,
post_modified,
post_modified_gmt,
post_content_filtered,
post_parent,
guid,
menu_order,
post_type,
post_mime_type,
comment_count,
old_post_author)
         SELECT 
                    ID,
                    post_author,
                    post_date,
                    post_date_gmt,
                    CONVERT(post_content USING utf8mb4) as post_content,
                    CONVERT(post_title USING utf8mb4) as post_title,
                    CONVERT(post_excerpt USING utf8mb4) as post_excerpt,
                    post_status,
                    comment_status,
                    ping_status,
                    CONVERT(post_name USING utf8mb4) as post_name,
                    to_ping,
                    pinged,
                    post_modified,
                    post_modified_gmt,
                    CONVERT(post_content_filtered USING utf8mb4) as post_content_filtered,
                    post_parent,
                    CONVERT(guid USING utf8mb4) as guid,
                    menu_order,
                    post_type,
                    post_mime_type,
                    comment_count,
                    post_author as old_post_author
                FROM {$this->oldDBName}.{$this->sourcePrefix}posts";
        $result = $wpdb->query($sql);

        if ($result === false) {
            WP_CLI::error('Failed to migrate posts: ' . $wpdb->last_error);
            return 0;
        }

        $count = $wpdb->rows_affected;
        WP_CLI::success(sprintf('Completed posts migration. Total posts migrated: %d', $count));

        // Get unique old author IDs to update
        $old_authors = $wpdb->get_col("
            SELECT DISTINCT old_post_author 
            FROM {$wpdb->dbname}.{$wpdb->posts} 
            WHERE old_post_author IS NOT NULL
        ");

        // Update post authors in bulk
        if (!empty($old_authors)) {
            WP_CLI::log("Now we're going to update post authors");
            $progress = \WP_CLI\Utils\make_progress_bar('Updating post authors', count($old_authors));

            foreach ($old_authors as $old_author_id) {
                $new_author_id = $this->getMappedUserId($old_author_id);
                $wpdb->update(
                    $wpdb->posts,
                    array('post_author' => $new_author_id, 'old_post_author' => null),
                    array('old_post_author' => $old_author_id),
                    array('%d', '%d'),
                    array('%d')
                );
                $progress->tick();
            }

            $progress->finish();
        }

        // Remove temporary column
        $wpdb->query("ALTER TABLE {$wpdb->posts} DROP COLUMN old_post_author");

        // Migrate postmeta
        $this->migratePostmeta();

        // Migrate taxonomies
        $this->migrateTaxonomies();

        return $count;
    }

    /**
     * Migrate post meta
     */
    private function migratePostmeta()
    {
        global $wpdb;

        WP_CLI::log("Now we're going to migrate post meta");
        $sql = "INSERT INTO {$wpdb->postmeta} 
                SELECT 
                    meta_id,
                    post_id,
                    meta_key,
                    CONVERT(meta_value USING utf8mb4) as meta_value
                FROM {$this->oldDBName}.{$this->sourcePrefix}postmeta";

        $result = $wpdb->query($sql);

        if ($result === false) {
            WP_CLI::error('Failed to migrate post meta: ' . $wpdb->last_error);
            return 0;
        }

        $meta_count = $wpdb->rows_affected;
        WP_CLI::success(sprintf('Completed post meta migration. Total meta entries: %d', $meta_count));
        return $meta_count;
    }

    /**
     * Migrate taxonomies
     */
    private function migrateTaxonomies()
    {
        global $wpdb;

        WP_CLI::log("Now we're going to migrate taxonomies");

        // Migrate terms
        $sql = "INSERT INTO {$wpdb->terms} 
                SELECT 
                    term_id,
                    CONVERT(name USING utf8mb4) as name,
                    CONVERT(slug USING utf8mb4) as slug,
                    term_group
                FROM {$this->oldDBName}.{$this->sourcePrefix}terms";

        $result = $wpdb->query($sql);

        if ($result === false) {
            WP_CLI::error('Failed to migrate terms: ' . $wpdb->last_error);
            return 0;
        }

        // Migrate term taxonomy
        $sql = "INSERT INTO {$wpdb->term_taxonomy} 
                SELECT 
                    term_taxonomy_id,
                    term_id,
                    CONVERT(taxonomy USING utf8mb4) as taxonomy,
                    CONVERT(description USING utf8mb4) as description,
                    parent,
                    count
                FROM {$this->oldDBName}.{$this->sourcePrefix}term_taxonomy";

        $result = $wpdb->query($sql);

        if ($result === false) {
            WP_CLI::error('Failed to migrate term taxonomy: ' . $wpdb->last_error);
            return 0;
        }

        // Migrate term relationships
        $sql = "INSERT INTO {$wpdb->term_relationships} 
                SELECT 
                    object_id,
                    term_taxonomy_id,
                    term_order
                FROM {$this->oldDBName}.{$this->sourcePrefix}term_relationships";

        $result = $wpdb->query($sql);

        if ($result === false) {
            WP_CLI::error('Failed to migrate term relationships: ' . $wpdb->last_error);
            return 0;
        }

        WP_CLI::success('Completed taxonomy migration');
        return true;
    }

    /**
     * Update media data (attachments)
     */
    public function updateMediaData()
    {
        WP_CLI::log("Now we're going to migrate media data");
        $attachments = $this->source_db->get_results("
            SELECT 
                p.*,
                CONVERT(pm.meta_value USING utf8mb4) as _wp_attached_file,
                CONVERT(pm2.meta_value USING utf8mb4) as _wp_attachment_metadata
            FROM {$this->sourcePrefix}posts p 
            LEFT JOIN {$this->sourcePrefix}postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
            LEFT JOIN {$this->sourcePrefix}postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wp_attachment_metadata'
            WHERE p.post_type = 'attachment'
        ");

        $count = 0;
        WP_CLI::log(sprintf('Starting media data migration. Total attachments: %d', count($attachments)));
        $progress = \WP_CLI\Utils\make_progress_bar('Updating media data', count($attachments));

        foreach ($attachments as $attachment) {
            // Update attachment metadata
            if ($attachment->_wp_attached_file) {
                update_post_meta($attachment->ID, '_wp_attached_file', $attachment->_wp_attached_file);
            }
            if ($attachment->_wp_attachment_metadata) {
                update_post_meta($attachment->ID, '_wp_attachment_metadata', maybe_unserialize($attachment->_wp_attachment_metadata));
            }
            $count++;
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success(sprintf('Completed media data migration. Total attachments: %d', $count));

        return $count;
    }

    /**
     * Migrate extra tables
     */
    public function migrateExtraTables($cleanup = true, $force = false)
    {
        WP_CLI::log("Now we're going to migrate extra tables");
        if (!$this->migrateExtraTables) {
            return 0;
        }

        if ($cleanup) {
            $this->cleanupExtraTables($force);
        }

        // Get all tables from source database
        $tables = $this->source_db->get_results("SHOW TABLES LIKE '{$this->sourcePrefix}%'");
        $count = 0;

        WP_CLI::log(sprintf('Starting extra tables migration. Total tables: %d', count($tables)));
        $progress = \WP_CLI\Utils\make_progress_bar('Migrating extra tables', count($tables));

        foreach ($tables as $table) {
            $table_name = array_values((array) $table)[0];

            // Skip WordPress core tables
            $core_tables = array(
                'posts',
                'postmeta',
                'comments',
                'commentmeta',
                'terms',
                'termmeta',
                'term_taxonomy',
                'term_relationships',
                'options',
                'users',
                'usermeta',
                'links'
            );

            if (in_array(str_replace($this->sourcePrefix, '', $table_name), $core_tables)) {
                continue;
            }

            // Get table data
            $rows = $this->source_db->get_results("SELECT * FROM {$table_name}");

            // Create table in target if it doesn't exist
            $target_table = str_replace($this->sourcePrefix, $this->targetPrefix, $table_name);
            $this->createTableIfNotExists($table_name, $target_table);

            // Insert data
            foreach ($rows as $row) {
                $this->source_db->insert($target_table, (array) $row);
            }

            $count++;
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success(sprintf('Completed extra tables migration. Total tables: %d', $count));

        return $count;
    }

    /**
     * Create table in target if it doesn't exist
     */
    private function createTableIfNotExists($source_table, $target_table)
    {
        global $wpdb;

        // Get table structure
        $create_table = $this->source_db->get_row("SHOW CREATE TABLE {$source_table}", ARRAY_N);
        if (!$create_table) {
            return false;
        }

        // Replace table name and prefix
        $create_table_sql = str_replace(
            array($source_table, $this->sourcePrefix),
            array($target_table, $this->targetPrefix),
            $create_table[1]
        );

        // Create table
        $wpdb->query($create_table_sql);
    }

    /**
     * Get mapped user ID
     */
    private function getMappedUserId($old_user_id)
    {
        $mapping = $this->db->getUserMigration($this->targetSiteID, $old_user_id);
        return $mapping ? $mapping->new_user_id : 1; // Default to admin if no mapping found
    }

    private function getUnusedOptions()
    {
        $file = OPMSM_PLUGIN_DIR . 'settings/unused-options.php';
        if (!file_exists($file)) {
            return array(); // Return empty array if file doesn't exist
        }

        // Load settings file only when needed
        if (!function_exists('opmsm_getUnusedOptions')) {
            require_once $file;
        }
        return opmsm_getUnusedOptions();
    }
}