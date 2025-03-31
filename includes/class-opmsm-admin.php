<?php
if (!defined('ABSPATH')) {
    exit;
}

class OPMSM_Admin {
    private $db;

    public function __construct() {
        $this->db = OPMSM_DB::get_instance();
        
        // Only add menu if we're in the network admin area
        if (is_network_admin()) {
            add_action('network_admin_menu', array($this, 'addAdminMenu'));
            add_action('network_admin_edit_opmsm_settings', array($this, 'saveNetworkSettings'));
            add_action('network_admin_notices', array($this, 'showAdminNotices'));
            add_action('admin_init', array($this, 'maybe_sync_sites'));
        }
    }

    /**
     * Maybe sync sites
     */
    public function maybe_sync_sites() {
        if (!is_network_admin()) {
            return;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== 'opmsm-migration') {
            return;
        }

        if (isset($_POST['opmsm_save_settings'])) {
            return;
        }

        $this->db->syncSites();
    }

    /**
     * Add admin menu
     */
    public function addAdminMenu() {
        add_menu_page(
            __('OPMSM Migration', 'wpmultisite-migrate'),
            __('OPMSM Migration', 'wpmultisite-migrate'),
            'manage_network',
            'opmsm-migration',
            array($this, 'renderAdminPage'),
            'dashicons-database-import',
            30
        );
    }

    /**
     * Render main admin page
     */
    public function renderAdminPage() {
        if (!current_user_can('manage_network')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wpmultisite-migrate'));
        }

        // Handle form submissions before any output
        if (isset($_POST['submit'])) {
            check_admin_referer('opmsm_edit_site');
            
            $data = array(
                'site_id' => intval($_POST['site_id']),
                'db_name' => sanitize_text_field($_POST['db_name']),
                'db_prefix' => sanitize_text_field($_POST['db_prefix']),
                'migrate_extra_tables' => isset($_POST['migrate_extra_tables']) ? 1 : 0,
                'clean_unused_data' => isset($_POST['clean_unused_data']) ? 1 : 0,
                'migration_status' => 'pending'
            );

            $site_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($site_id) {
                $this->db->updateUnisite($site_id, $data);
            } else {
                $this->db->addUnisite($data);
            }

            wp_redirect(network_admin_url('admin.php?page=opmsm-migration&updated=1'));
            exit;
        }

        $action = isset($_GET['action']) ? $_GET['action'] : 'list';

        switch ($action) {
            case 'edit':
                $this->renderEditPage();
                break;
            case 'viewstatus':
                $this->renderProgressPage();
                break;
            default:
                $this->renderListPage();
                break;
        }
    }

    /**
     * Render list page
     */
    private function renderListPage() {
        // Handle site deletion
        if (isset($_POST['delete_site']) && isset($_POST['site_id'])) {
            check_admin_referer('opmsm_delete_site');
            $this->db->deleteUnisite(intval($_POST['site_id']));
            wp_redirect(add_query_arg('deleted', '1', network_admin_url('admin.php?page=opmsm-migration')));
            exit;
        }

        // Get all sites
        $sites = $this->db->getAllUnisites();
        ?>
        <div class="wrap">
            <h1><?php _e('OPMSM Migration', 'wpmultisite-migrate'); ?></h1>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <a href="<?php echo network_admin_url('admin.php?page=opmsm-migration&action=edit'); ?>" class="button button-primary">
                        <?php _e('Add New Site', 'wpmultisite-migrate'); ?>
                    </a>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Site ID', 'wpmultisite-migrate'); ?></th>
                        <th><?php _e('Database Name', 'wpmultisite-migrate'); ?></th>
                        <th><?php _e('Table Prefix', 'wpmultisite-migrate'); ?></th>
                        <th><?php _e('Migration Status', 'wpmultisite-migrate'); ?></th>
                        <th><?php _e('Actions', 'wpmultisite-migrate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sites)) : ?>
                        <tr>
                            <td colspan="5"><?php _e('No sites found.', 'wpmultisite-migrate'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($sites as $site) : ?>
                            <tr>
                                <td><?php echo esc_html($site->site_id); ?></td>
                                <td><?php echo esc_html($site->db_name); ?></td>
                                <td><?php echo esc_html($site->db_prefix); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($site->migration_status); ?>">
                                        <?php echo esc_html(ucfirst($site->migration_status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo network_admin_url('admin.php?page=opmsm-migration&action=edit&id=' . $site->id); ?>" class="button button-small">
                                        <?php _e('Edit', 'wpmultisite-migrate'); ?>
                                    </a>
                                    <a href="<?php echo network_admin_url('admin.php?page=opmsm-migration&action=viewstatus&id=' . $site->id); ?>" class="button button-small">
                                        <?php _e('View Progress', 'wpmultisite-migrate'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <style>
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                line-height: 1;
                font-weight: 500;
            }
            .status-pending {
                background-color: #f0f0f1;
                color: #50575e;
            }
            .status-in_progress {
                background-color: #fff3cd;
                color: #856404;
            }
            .status-completed {
                background-color: #d4edda;
                color: #155724;
            }
            .status-failed {
                background-color: #f8d7da;
                color: #721c24;
            }
        </style>
        <?php
    }

    /**
     * Render edit page
     */
    public function renderEditPage() {
        if (!current_user_can('manage_network')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wpmultisite-migrate'));
        }

        $site_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $site = $site_id ? $this->db->getUnisite($site_id) : null;
        ?>
        <div class="wrap">
            <h1><?php echo $site_id ? __('Edit Site', 'wpmultisite-migrate') : __('Add New Site', 'wpmultisite-migrate'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('opmsm_edit_site'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="site_id"><?php _e('Site ID', 'wpmultisite-migrate'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="site_id" id="site_id" class="regular-text" 
                                   value="<?php echo esc_attr($site ? $site->site_id : ''); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="db_name"><?php _e('Database Name', 'wpmultisite-migrate'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="db_name" id="db_name" class="regular-text" 
                                   value="<?php echo esc_attr($site ? $site->db_name : ''); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="db_prefix"><?php _e('Table Prefix', 'wpmultisite-migrate'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="db_prefix" id="db_prefix" class="regular-text" 
                                   value="<?php echo esc_attr($site ? $site->db_prefix : ''); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Migration Options', 'wpmultisite-migrate'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="migrate_extra_tables" value="1" 
                                       <?php checked($site && $site->migrate_extra_tables); ?>>
                                <?php _e('Migrate extra tables', 'wpmultisite-migrate'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="clean_unused_data" value="1" 
                                       <?php checked($site && $site->clean_unused_data); ?>>
                                <?php _e('Clean unused data', 'wpmultisite-migrate'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e('Save Site', 'wpmultisite-migrate'); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render progress page
     */
    public function renderProgressPage() {
        if (!current_user_can('manage_network')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wpmultisite-migrate'));
        }

        $site_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$site_id) {
            wp_die(__('Invalid site ID.', 'wpmultisite-migrate'));
        }

        $site = $this->db->getUnisite($site_id);
        if (!$site) {
            wp_die(__('Site not found.', 'wpmultisite-migrate'));
        }

        $stats = $this->db->getMigrationStats($site_id);
        ?>
        <div class="wrap">
            <h1><?php _e('Migration Progress', 'wpmultisite-migrate'); ?></h1>

            <div class="card">
                <h2><?php _e('Site Information', 'wpmultisite-migrate'); ?></h2>
                <p>
                    <strong><?php _e('Site ID:', 'wpmultisite-migrate'); ?></strong> <?php echo esc_html($site->site_id); ?><br>
                    <strong><?php _e('Database Name:', 'wpmultisite-migrate'); ?></strong> <?php echo esc_html($site->db_name); ?><br>
                    <strong><?php _e('Table Prefix:', 'wpmultisite-migrate'); ?></strong> <?php echo esc_html($site->db_prefix); ?><br>
                    <strong><?php _e('Status:', 'wpmultisite-migrate'); ?></strong> 
                    <span class="status-badge status-<?php echo esc_attr($site->migration_status); ?>">
                        <?php echo esc_html(ucfirst($site->migration_status)); ?>
                    </span>
                </p>
            </div>

            <?php if ($stats) : ?>
                <div class="card">
                    <h2><?php _e('Migration Statistics', 'wpmultisite-migrate'); ?></h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Item', 'wpmultisite-migrate'); ?></th>
                                <th><?php _e('Progress', 'wpmultisite-migrate'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php _e('Users', 'wpmultisite-migrate'); ?></td>
                                <td><?php echo esc_html($stats->users_migrated); ?>/<?php echo esc_html($stats->total_users); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Posts', 'wpmultisite-migrate'); ?></td>
                                <td><?php echo esc_html($stats->posts_migrated); ?>/<?php echo esc_html($stats->total_posts); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Media', 'wpmultisite-migrate'); ?></td>
                                <td><?php echo esc_html($stats->media_migrated); ?>/<?php echo esc_html($stats->total_media); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Post Meta', 'wpmultisite-migrate'); ?></td>
                                <td><?php echo esc_html($stats->postmeta_migrated); ?>/<?php echo esc_html($stats->total_postmeta); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2><?php _e('CLI Commands', 'wpmultisite-migrate'); ?></h2>
                <p><?php _e('You can use the following WP-CLI commands to manage the migration:', 'wpmultisite-migrate'); ?></p>
                <pre><code># Run full migration
wp opmsm migrate <?php echo esc_html($site->site_id); ?>

# Run specific stage
wp opmsm migrate <?php echo esc_html($site->site_id); ?> --stage=users
wp opmsm migrate <?php echo esc_html($site->site_id); ?> --stage=options
wp opmsm migrate <?php echo esc_html($site->site_id); ?> --stage=posts
wp opmsm migrate <?php echo esc_html($site->site_id); ?> --stage=media
wp opmsm migrate <?php echo esc_html($site->site_id); ?> --stage=extra

# Force cleanup in production
wp opmsm migrate <?php echo esc_html($site->site_id); ?> --force

# Check progress
wp opmsm progress <?php echo esc_html($site->site_id); ?></code></pre>
            </div>
        </div>

        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                margin-top: 20px;
                padding: 20px;
            }
            .card h2 {
                margin-top: 0;
            }
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                line-height: 1;
                font-weight: 500;
            }
            .status-pending {
                background-color: #f0f0f1;
                color: #50575e;
            }
            .status-in_progress {
                background-color: #fff3cd;
                color: #856404;
            }
            .status-completed {
                background-color: #d4edda;
                color: #155724;
            }
            .status-failed {
                background-color: #f8d7da;
                color: #721c24;
            }
            pre {
                background: #f0f0f1;
                padding: 15px;
                border-radius: 3px;
                overflow-x: auto;
            }
            code {
                font-family: monospace;
            }
        </style>
        <?php
    }

    /**
     * Save network settings
     */
    public function saveNetworkSettings() {
        check_admin_referer('opmsm_network_settings');
        
        if (!current_user_can('manage_network')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wpmultisite-migrate'));
        }

        // Save settings here
        wp_redirect(network_admin_url('admin.php?page=opmsm-migration&updated=1'));
        exit;
    }

    /**
     * Show admin notices
     */
    public function showAdminNotices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'opmsm-migration') {
            return;
        }

        if (isset($_GET['updated'])) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved successfully.', 'wpmultisite-migrate'); ?></p>
            </div>
            <?php
        }

        if (isset($_GET['deleted'])) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Site deleted successfully.', 'wpmultisite-migrate'); ?></p>
            </div>
            <?php
        }
    }
} 