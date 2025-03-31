<?php
if (!defined('ABSPATH')) {
    exit;
}

class OPMSM_CLI {
    private $db;

    public function __construct() {
        $this->db = OPMSM_DB::get_instance();
        
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('opmsm', $this);
        }
    }

    /**
     * List all sites configured for migration
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp opmsm list
     *     wp opmsm list --format=json
     *
     * @subcommand list
     */
    public function list() {
        $unisites = $this->db->getAllUnisites();
        
        if (empty($unisites)) {
            WP_CLI::log(__('No sites found.', 'wpmultisite-migrate'));
            return;
        }

        $table = new \cli\Table();
        $table->setHeaders(array(
            'ID',
            'Site ID',
            'Database Name',
            'Table Prefix',
            'Status'
        ));

        foreach ($unisites as $unisite) {
            $table->addRow(array(
                $unisite->id,
                $unisite->site_id,
                $unisite->db_name,
                $unisite->db_prefix,
                $unisite->migration_status
            ));
        }

        $table->display();
    }

    /**
     * Migrate data from source to target site
     *
     * ## OPTIONS
     *
     * <site_id>
     * : The ID of the site to migrate
     *
     * [--stage=<stage>]
     * : The migration stage to run (users, options, posts, media, extra)
     *
     * [--force]
     * : Force cleanup even in production environment
     *
     * ## EXAMPLES
     *
     *     # Run full migration for a site
     *     wp opmsm migrate 2
     *
     *     # Run specific stage for a site
     *     wp opmsm migrate 2 --stage=users
     *
     *     # Run migration with force cleanup
     *     wp opmsm migrate 2 --force
     *
     *     # Run specific stage with force cleanup
     *     wp opmsm migrate 2 --stage=posts --force
     */
    public function migrate($args, $assoc_args) {
        $site_id = $args[0];
        $stage = isset($assoc_args['stage']) ? $assoc_args['stage'] : '';
        $force = isset($assoc_args['force']);

        // Get site info
        $unisite = $this->db->getUnisiteBySiteId($site_id);
        if (!$unisite) {
            WP_CLI::error("Site with ID {$site_id} not found.");
            return;
        }

        // Create processor instance
        $processor = new OPMSM_Processor($unisite);

        // Update migration status
        $this->db->updateUnisite($unisite->id, array('migration_status' => 'in_progress'));

        try {
            if (empty($stage)) {
                // Run full migration
                $processor->migrateUsers(true, $force);
                $processor->migrateOptions(true, $force);
                $processor->migratePosts(true, $force);
                $processor->updateMediaData();
                $processor->migrateExtraTables(true, $force);
                
                // Update migration status
                $this->db->updateUnisite($unisite->id, array('migration_status' => 'completed'));
                WP_CLI::success("Migration completed successfully for site {$site_id}");
            } else {
                // Run specific stage
                switch ($stage) {
                    case 'users':
                        $processor->migrateUsers(true, $force);
                        break;
                    case 'options':
                        $processor->migrateOptions(true, $force);
                        break;
                    case 'posts':
                        $processor->migratePosts(true, $force);
                        break;
                    case 'media':
                        $processor->updateMediaData();
                        break;
                    case 'extra':
                        $processor->migrateExtraTables(true, $force);
                        break;
                    default:
                        WP_CLI::error("Invalid stage: {$stage}");
                        return;
                }
                WP_CLI::success("Stage '{$stage}' completed successfully for site {$site_id}");
            }
        } catch (Exception $e) {
            // Update migration status
            $this->db->updateUnisite($unisite->id, array('migration_status' => 'failed'));
            WP_CLI::error("Migration failed: " . $e->getMessage());
        }
    }

    /**
     * Show migration progress for a site
     *
     * ## OPTIONS
     *
     * <site_id>
     * : The ID of the site to check
     *
     * [--stage=<stage>]
     * : The migration stage to check (users, options, posts, media, extra)
     *
     * ## EXAMPLES
     *
     *     # Show full progress for a site
     *     wp opmsm progress 2
     *
     *     # Show progress for specific stage
     *     wp opmsm progress 2 --stage=users
     */
    public function progress($args, $assoc_args) {
        $site_id = $args[0];

        // Get site info
        $unisite = $this->db->getUnisiteBySiteId($site_id);
        if (!$unisite) {
            WP_CLI::error("Site with ID {$site_id} not found.");
            return;
        }

        // Show migration status
        WP_CLI::log("Migration Status for Site {$site_id}:");
        WP_CLI::log("----------------------------------------");
        WP_CLI::log("Status: " . ucfirst($unisite->migration_status));
        WP_CLI::log("----------------------------------------");
    }
} 