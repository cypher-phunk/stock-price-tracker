<?php

if (!defined('ABSPATH')) exit;

class SDP_PG
{
    private static $I;
    private $pdo = null;

    private $cpt_company = 'company';  // researcher source
    private $cpt_report  = 'report';   // report
    private $db_schema;
    
    // if url contains activ8insights, use dashboard schema
    public function get_db_schema() {
        if (strpos(site_url(), 'activ8insights') !== false) {
            
            return 'production';
        }
        return 'localwp';
    }

    // ACF field handles on report posts:
    private $acf_researcher_company = 'researcher_company'; // Post Object to 'company' CPT
    private $acf_company_id         = 'company_id';         // int company_id
    private $acf_report_date        = 'report_date';        // 'Y-m-d'

    private $backfill_state_option = 'activ8_backfill_state';
    private $backfill_log_transient = 'activ8_backfill_log';
    private $backfill_lock_transient = 'activ8_backfill_lock';
    private $backfill_cron_hook = 'activ8_backfill_process';

    static function i()
    {
        return self::$I ?: self::$I = new self;
    }

    function __construct()
    {
        add_action("acf/save_post", [$this, 'on_save_company'], 21, 3);
        add_action("acf/save_post",  [$this, 'on_save_report'], 22, 3);

        if (defined('WP_CLI')) {
            \WP_CLI::add_command('activ8 backfill-researchers', [$this, 'cli_backfill_researchers']);
            \WP_CLI::add_command('activ8 backfill-stocks',      [$this, 'cli_backfill_stocks']);
            \WP_CLI::add_command('activ8 backfill-reports',     [$this, 'cli_backfill_reports']);
        }

        add_action('admin_post_activ8_run_functions', [$this, 'handle_run_functions_submit']);
        add_action($this->backfill_cron_hook, [$this, 'cron_process_backfill']);
        $this->db_schema = $this->get_db_schema();
    }



    /*** PDO ***/
    private function pdo(): PDO
    {
        if ($this->pdo) return $this->pdo;

        $host = sdp_decrypt_api_key(get_option('sdp_postgres_host'));
        $port = sdp_decrypt_api_key(get_option('sdp_postgres_port'));
        $db   = sdp_decrypt_api_key(get_option('sdp_postgres_db'));
        $user = sdp_decrypt_api_key(get_option('sdp_postgres_user'));
        $pass = sdp_decrypt_api_key(get_option('sdp_postgres_password'));
        $ssl  = 'require';

        $dsn  = "pgsql:host={$host};port={$port};dbname={$db};sslmode={$ssl}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log("[A8PG] PDO connection failed: " . $e->getMessage());
            throw $e;
        }

        return $this->pdo;
    }

    /*** RESEARCHERS: from CPT 'company' ***/
    function on_save_company($post_id, $post, $update)
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if ($post->post_type !== $this->cpt_company) return;
        // do_action('acf/save_post', $post_id); // ensure ACF fields are saved

        // researcher_name: use raw post_title to avoid WordPress adding "Private:"/"Protected:" prefixes
        $researcher_name = $post->post_title ?: $post->post_name;
        $researcher_name = preg_replace('/^(?:Private|Protected):\s*/i', '', $researcher_name);
        $researcher_name = html_entity_decode($researcher_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        try {
            $sql = <<<SQL
INSERT INTO {$this->db_schema}.researchers (researcher_id, researcher_name, updated_at)
VALUES (:id, :name, NOW())
ON CONFLICT (researcher_id) DO UPDATE SET
    researcher_name = EXCLUDED.researcher_name,
    updated_at      = NOW();
SQL;
            $st = $this->pdo()->prepare($sql);
            $st->execute([':id' => $post_id, ':name' => $researcher_name]);
        } catch (Exception $e) {
            error_log("[A8PG] researcher upsert failed for post {$post_id}: " . $e->getMessage());
        }
    }

    /*** STOCK_INFO: combine wp_stock_company_info + wp_stock_tickers ***/
    public function cli_backfill_stocks()
    {
        $this->backfill_stocks_batch(0, 1000000000);
    }

    private function backfill_stocks_batch(int $offset, int $limit): array
    {
        global $wpdb;
        $tblCo = $wpdb->prefix . 'stock_company_info';
        $tblTi = $wpdb->prefix . 'stock_tickers';

        if (defined('WP_CLI')) {
            \WP_CLI::log("Checking tables: {$tblCo} and {$tblTi}");
        }

        // First, let's check if the tables exist and have data
        $company_count = $wpdb->get_var("SELECT COUNT(*) FROM {$tblCo}");
        $ticker_count = $wpdb->get_var("SELECT COUNT(*) FROM {$tblTi}");

        if (defined('WP_CLI')) {
            \WP_CLI::log("Found {$company_count} companies and {$ticker_count} tickers");
        }

        if ($company_count == 0) {
            if (defined('WP_CLI')) {
                \WP_CLI::warning("No data found in {$tblCo} table. Check table name and data.");
            }
            return ['processed' => 0, 'has_more' => false];
        }

        $company_columns = $wpdb->get_results("DESCRIBE {$tblCo}", ARRAY_A);
        $ticker_columns = $wpdb->get_results("DESCRIBE {$tblTi}", ARRAY_A);

        if (defined('WP_CLI')) {
            \WP_CLI::log("Company table columns: " . implode(', ', array_column($company_columns, 'Field')));
            \WP_CLI::log("Ticker table columns: " . implode(', ', array_column($ticker_columns, 'Field')));
        }

        $sql = $wpdb->prepare(
            "SELECT c.id as company_id, c.name as company_name, c.industry, c.sector,
                    t.symbol AS ticker
               FROM {$tblCo} c
               LEFT JOIN {$tblTi} t ON t.id = c.ticker_id
              WHERE t.symbol IS NOT NULL AND t.symbol != ''
              ORDER BY c.id ASC
              LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        if (defined('WP_CLI')) {
            \WP_CLI::log("Executing query: " . preg_replace('/\s+/', ' ', trim($sql)));
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error) {
            if (defined('WP_CLI')) {
                \WP_CLI::error("SQL Error: " . $wpdb->last_error);
            }
            return ['processed' => 0, 'has_more' => false];
        }

        if (empty($rows)) {
            if (defined('WP_CLI')) {
                \WP_CLI::warning('No rows returned from query. This might be due to column name mismatches.');

                // Let's try a simpler query to see if we can get any data
                $simple_test = $wpdb->get_results("SELECT * FROM {$tblCo} LIMIT 5", ARRAY_A);
                if (!empty($simple_test)) {
                    \WP_CLI::log("Sample data from {$tblCo}:");
                    foreach ($simple_test as $row) {
                        \WP_CLI::log(print_r($row, true));
                    }
                }
            }
            return ['processed' => 0, 'has_more' => false];
        }

        //if (defined('WP_CLI')) {
        //    $bar = \WP_CLI\Utils\make_progress_bar('Backfilling stock_info', count($rows));
        //}

        $processed = 0;
        try {
            // Check if the company_id already exists
            $checkSql = "SELECT 1 FROM {$this->db_schema}.stock_info WHERE company_id = :id";
            $checkSt = $this->pdo()->prepare($checkSql);

            $sqlInsert = <<<SQL
            INSERT INTO {$this->db_schema}.stock_info (company_id, ticker, company_name, industry, sector, updated_at)
            VALUES (:id, :ticker, :name, :industry, :sector, NOW());
            SQL;

            $sqlUpdate = <<<SQL
            UPDATE {$this->db_schema}.stock_info
            SET ticker = :ticker,
                company_name = :name,
                industry = :industry,
                sector = :sector,
                updated_at = NOW()
            WHERE company_id = :id;
            SQL;

            // Prepare both statements
            $insertSt = $this->pdo()->prepare($sqlInsert);
            $updateSt = $this->pdo()->prepare($sqlUpdate);

            foreach ($rows as $r) {
                // Check if record exists
                $checkSt->execute([':id' => (int)$r['company_id']]);
                $exists = $checkSt->fetch() !== false;

                // Prepare parameters
                $params = [
                    ':id' => (int)$r['company_id'],
                    ':ticker' => $r['ticker'] ?: null,
                    ':name' => $r['company_name'] ?: '',
                    ':industry' => $r['industry'] ?: null,
                    ':sector' => $r['sector'] ?: null,
                ];

                // Execute appropriate statement
                if ($exists) {
                    $result = $updateSt->execute($params);
                } else {
                    $result = $insertSt->execute($params);
                }

                if (!$result) {
                    if (defined('WP_CLI')) {
                        \WP_CLI::warning("Failed to process company_id {$r['company_id']}");
                    }
                    continue;
                }

                if (isset($bar)) {
                    $bar->tick();
                }

                $processed++;
            }

            if (isset($bar)) {
                $bar->finish();
                \WP_CLI::success('stock_info backfill complete.');
            }
        } catch (Exception $e) {
            if (defined('WP_CLI')) {
                \WP_CLI::error('Backfill failed: ' . $e->getMessage());
            }
            throw $e;
        }

        $has_more = count($rows) === $limit;
        return ['processed' => $processed, 'has_more' => $has_more];
    }

    /*** RESEARCHERS backfill: all 'company' posts ***/
    public function cli_backfill_researchers()
    {
        if (defined('WP_CLI')) {
            \WP_CLI::log('Starting researchers backfill from company posts...');
        }

        try {
            $this->backfill_cpt($this->cpt_company, function ($p) {
                $this->on_save_company($p->ID, $p, true);
            });
        } catch (Exception $e) {
            if (defined('WP_CLI')) {
                \WP_CLI::error('Researchers backfill failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /*** REPORTS: Fill PostgreSQL from WordPress posts ***/
    public function on_save_report($post_id, $post, $update)
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if ($post->post_type !== $this->cpt_report) return;
        // do_action('acf/save_post', $post_id); // ensure ACF fields are saved

        // Get ACF relationship fields (these return post IDs)
        $researcher_post_id = get_field('research_company', $post_id); // Post ID of researcher
        $stock_post_id = get_field('symbol', $post_id); // Post ID of stock post
        $report_date = get_field('report_date', $post_id); // Date in m/d/Y format
        $report_id = $post_id; // report Id

        if (defined('WP_CLI')) {
            \WP_CLI::log("Raw ACF values: researcher_post_id=" . print_r($researcher_post_id, true) . ", stock_post_id=" . print_r($stock_post_id, true) . ", report_date={$report_date}");
        }

        // Extract IDs from ACF objects if needed
        $researcher_id = $this->extract_id($researcher_post_id);
        $stock_id = $this->extract_id($stock_post_id);

        if (defined('WP_CLI')) {
            \WP_CLI::log("Processing post {$post_id}: researcher_post={$researcher_id}, stock_post={$stock_id}, date={$report_date}");
        }

        // Validate we have the basic required fields
        if (!$researcher_id || !$stock_id || !$report_date || !$report_id) {
            $message = "[A8PG] Skipping post {$post_id} - Missing ACF data: researcher={$researcher_id}, stock={$stock_id}, date={$report_date}, report_id={$report_id}";
            error_log($message);
            if (defined('WP_CLI')) {
                \WP_CLI::warning($message);
            }
            return;
        }

        // Get ticker from the stock post
        $ticker = $this->get_ticker_from_stock_post($stock_id);
        if (!$ticker) {
            $message = "[A8PG] Skipping post {$post_id} - Could not get ticker from stock post {$stock_id}";
            error_log($message);
            if (defined('WP_CLI')) {
                \WP_CLI::warning($message);
            }
            return;
        }

        $stock_ticker_pkey = $this->get_company_id_by_ticker($ticker);
        if (!$stock_ticker_pkey) {
            $message = "[A8PG] Skipping post {$post_id} - Could not find stock_info entry for ticker '{$ticker}'";
            error_log($message);
            if (defined('WP_CLI')) {
                \WP_CLI::warning($message);
            }
            return;
        }

        $stock_id = $stock_ticker_pkey;

        // Get company_id from database using ticker
        $company_id = $this->get_company_id_by_ticker($ticker);
        if (!$company_id) {
            $message = "[A8PG] Skipping post {$post_id} - Could not find company_id for ticker '{$ticker}'";
            error_log($message);
            if (defined('WP_CLI')) {
                \WP_CLI::warning($message);
            }
            return;
        }

        // Format date from m/d/Y to Y-m-d
        $formatted_date = $this->format_date_from_mdy($report_date);
        if (!$formatted_date) {
            $message = "[A8PG] Skipping post {$post_id} - Invalid date format: '{$report_date}'";
            error_log($message);
            if (defined('WP_CLI')) {
                \WP_CLI::warning($message);
            }
            return;
        }

        // Update Report Taxonomy terms
        // sector, industry, report_type
        
        $report_type = get_field('report_type', $post_id);

        if (defined('WP_CLI')) {
            \WP_CLI::log("Final data: researcher_id={$researcher_id}, company_id={$company_id}, ticker={$ticker}, date={$formatted_date}");
        }

        // Insert/update in PostgreSQL
        try {
            $sql = <<<SQL
            INSERT INTO {$this->db_schema}.reports (researcher_id, company_id, reportdts, report_id, updated_at)
            VALUES (:rid, :cid, :d, :pid, NOW())
            ON CONFLICT (report_id)
            DO UPDATE SET
                researcher_id = EXCLUDED.researcher_id,
                company_id    = EXCLUDED.company_id,
                reportdts     = EXCLUDED.reportdts,
                updated_at    = NOW();
            SQL;

            $st = $this->pdo()->prepare($sql);
            $result = $st->execute([
                ':rid' => (int)$researcher_id,
                ':cid' => (int)$company_id,
                ':d' => $formatted_date,
                ':pid' => (int)$report_id
            ]);

            if ($result) {
                if (defined('WP_CLI')) {
                    \WP_CLI::log("✓ Successfully processed post {$post_id} - {$ticker} report by researcher {$researcher_id}");
                }
            }
        } catch (Exception $e) {
            $message = "[A8PG] Database error for post {$post_id}: " . $e->getMessage();
            error_log($message);
            if (defined('WP_CLI')) {
                \WP_CLI::error($message);
            }
        }
    }

    public function save_stock_to_postgres($ticker)
    {
        if (!$ticker) {
            return;
        }
        global $wpdb;
        $tblCo = $wpdb->prefix . 'stock_company_info';
        $tblTi = $wpdb->prefix . 'stock_tickers';
        if (defined('WP_CLI')) {
            \WP_CLI::log("Checking tables: {$tblCo} and {$tblTi}");
        }
        $sql = $wpdb->prepare("
            SELECT c.id as company_id, c.name as company_name, c.industry, c.sector,
               t.symbol AS ticker
            FROM {$tblCo} c
            LEFT JOIN {$tblTi} t ON t.id = c.ticker_id
            WHERE t.symbol = %s
            LIMIT 1
        ", $ticker);
        $result = $wpdb->get_row($sql);
        if (defined('WP_CLI')) {
            \WP_CLI::log("Database result for ticker '{$ticker}': " . print_r($result, true));
        }
        if (!$result) {
            if (defined('WP_CLI')) {
                \WP_CLI::warning("No data found for ticker '{$ticker}'");
            }
            return;
        }
        try {
            // Check if the company_id already exists
            $checkSql = "SELECT 1 FROM {$this->db_schema}.stock_info WHERE company_id = :id";
            $checkSt = $this->pdo()->prepare($checkSql);
            $checkSt->execute([':id' => $result->company_id]);
            $exists = $checkSt->fetchColumn();

            // Prepare insert or update
            if ($exists) {
                $sqlUpdate = <<<SQL
                UPDATE {$this->db_schema}.stock_info
                SET ticker = :ticker
                WHERE company_id = :id
                SQL;
                $updateSt = $this->pdo()->prepare($sqlUpdate);
                $updateSt->execute([
                    ':ticker' => $ticker,
                    ':id' => $result->company_id
                ]);
            } else {
                $sqlInsert = <<<SQL
                INSERT INTO {$this->db_schema}.stock_info (company_id, ticker)
                VALUES (:id, :ticker)
                SQL;
                $insertSt = $this->pdo()->prepare($sqlInsert);
                $insertSt->execute([
                    ':id' => $result->company_id,
                    ':ticker' => $ticker
                ]);
            }
        } catch (Exception $e) {
            if (defined('WP_CLI')) {
                \WP_CLI::error("Error saving stock info: " . $e->getMessage());
            }
        }
    }

    /*** Get ticker symbol from stock post ***/
    private function get_ticker_from_stock_post($stock_post_id)
    {
        if (!$stock_post_id) {
            return null;
        }

        // Method 1: Check if ticker is stored as ACF field on the stock post
        $ticker = get_field('ticker', $stock_post_id);
        if ($ticker) {
            return strtoupper(trim($ticker));
        }

        // Method 2: Check if ticker is stored as post meta
        $ticker = get_post_meta($stock_post_id, 'ticker', true);
        if ($ticker) {
            return strtoupper(trim($ticker));
        }

        // Method 3: Use post title or slug as ticker (if that's how it's structured)
        $post = get_post($stock_post_id);
        if ($post) {
            // Maybe the post title is the ticker?
            $rx = '/^[A-Z0-9.\-]{1,15}$/';

            $title = trim($post->post_title);
            if (preg_match($rx, $title)) {
                return $title;
            }

            // Maybe the post slug is the ticker?
            $slug = strtoupper(trim($post->post_name));
            if (preg_match($rx, $slug)) {
                return $slug;
            }
        }

        return null;
    }

    /*** Get company_id by ticker from database ***/
    private function get_company_id_by_ticker($ticker)
    {
        if (!$ticker) {
            return null;
        }

        // First try PostgreSQL (if stock_info table exists)
        try {
            $sql = "SELECT company_id
                    FROM {$this->db_schema}.stock_info
                    WHERE UPPER(TRIM(ticker)) = UPPER(TRIM(:ticker))
                    LIMIT 1";
            $st = $this->pdo()->prepare($sql);
            $st->execute([':ticker' => strtoupper(trim($ticker))]);
            $result = $st->fetch();

            if ($result) {
                return (int)$result['company_id'];
            }
        } catch (Exception $e) {
            // PostgreSQL lookup failed, try MySQL
            if (defined('WP_CLI')) {
                \WP_CLI::log("PostgreSQL lookup failed for {$ticker}, trying MySQL: " . $e->getMessage());
            }
        }

        // Fallback to MySQL WordPress tables
        global $wpdb;
        $tblTi = $wpdb->prefix . 'stock_tickers';
        $tblCo = $wpdb->prefix . 'stock_company_info';

        $sql = "
                SELECT c.id as company_id
                FROM {$tblCo} c
                JOIN {$tblTi} t ON t.id = c.ticker_id
                WHERE UPPER(TRIM(t.symbol)) = UPPER(TRIM(%s))
                LIMIT 1
            ";

        $result = $wpdb->get_var($wpdb->prepare($sql, $ticker));
        return $result ? (int)$result : null;
    }

    /*** REPORTS backfill: all report posts ***/
    public function cli_backfill_reports()
    {
        if (defined('WP_CLI')) {
            \WP_CLI::log('Starting reports backfill...');
        }

        // Check if post type exists
        if (!post_type_exists($this->cpt_report)) {
            if (defined('WP_CLI')) {
                \WP_CLI::error("Post type '{$this->cpt_report}' does not exist!");
            }
            return;
        }

        // Get count of posts
        $post_counts = wp_count_posts($this->cpt_report);
        $total_posts = $post_counts->publish + $post_counts->draft + $post_counts->private;

        if (defined('WP_CLI')) {
            \WP_CLI::log("Found {$total_posts} report posts (published: {$post_counts->publish}, draft: {$post_counts->draft})");
        }

        if ($total_posts == 0) {
            if (defined('WP_CLI')) {
                \WP_CLI::warning('No report posts found to backfill.');
            }
            return;
        }

        try {
            $this->backfill_cpt($this->cpt_report, function ($p) {
                $this->on_save_report($p->ID, $p, true);
            });
        } catch (Exception $e) {
            if (defined('WP_CLI')) {
                \WP_CLI::error('Reports backfill failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /*** Helper function to extract ID from various ACF field types ***/
    private function extract_id($field_value)
    {
        // Handle null/empty values
        if (empty($field_value)) {
            return 0;
        }

        // Handle arrays (ACF relationship fields often return arrays)
        if (is_array($field_value)) {
            // If it's an array, get the first item
            $first_item = reset($field_value);

            // If the first item is a WP_Post object
            if (is_object($first_item) && isset($first_item->ID)) {
                return (int)$first_item->ID;
            }

            // If the first item is an array with ID
            if (is_array($first_item) && isset($first_item['ID'])) {
                return (int)$first_item['ID'];
            }

            // If the first item is just a number
            if (is_numeric($first_item)) {
                return (int)$first_item;
            }

            return 0;
        }

        // Handle single WP_Post objects
        if (is_object($field_value) && isset($field_value->ID)) {
            return (int)$field_value->ID;
        }

        // Handle arrays with ID key
        if (is_array($field_value) && isset($field_value['ID'])) {
            return (int)$field_value['ID'];
        }

        // Handle direct numeric values
        if (is_numeric($field_value)) {
            return (int)$field_value;
        }

        return 0;
    }

    /*** Helper function to format date from m/d/Y to Y-m-d ***/
    private function format_date_from_mdy($date_string)
    {
        if (empty($date_string)) {
            return null;
        }

        // Parse m/d/Y format
        $timestamp = strtotime($date_string);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /*** Helper function to validate date format ***/
    private function is_valid_date($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /*** Generic CPT backfill with paging ***/
    private function backfill_cpt($cpt, callable $fn)
    {
        $paged = 1;
        $per = 250;

        $q = new WP_Query([
            'post_type' => $cpt,
            'post_status' => 'any',
            'posts_per_page' => $per,
            'paged' => $paged,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false
        ]);

        // log found posts
        if (defined('WP_CLI')) {
            \WP_CLI::log("Found {$q->found_posts} posts for backfill.");
        }

        if (defined('WP_CLI')) {
            // $bar = \WP_CLI\Utils\make_progress_bar("Backfilling {$cpt}", (int)$q->found_posts);
        }

        while ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $fn($p);
                // if (isset($bar)) $bar->tick();
            }

            $paged++;
            $q = new WP_Query([
                'post_type' => $cpt,
                'post_status' => 'any',
                'posts_per_page' => $per,
                'paged' => $paged,
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => false
            ]);
        }

        if (isset($bar)) {
            // $bar->finish();
            \WP_CLI::success("{$cpt} backfill complete.");
        }
    }

    private function backfill_cpt_batch(string $cpt, callable $fn, int $paged, int $per): array
    {
        $q = new WP_Query([
            'post_type' => $cpt,
            'post_status' => 'any',
            'posts_per_page' => $per,
            'paged' => $paged,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        $processed = 0;
        if (!empty($q->posts)) {
            foreach ($q->posts as $p) {
                $fn($p);
                $processed++;
            }
        }

        wp_reset_postdata();

        $has_more = $processed === $per;
        return ['processed' => $processed, 'has_more' => $has_more];
    }

    private function backfill_log_append(string $line): void
    {
        $log = get_transient($this->backfill_log_transient);
        if (!is_array($log)) $log = [];
        $log[] = $line;
        set_transient($this->backfill_log_transient, $log, MINUTE_IN_SECONDS * 30);
    }

    private function backfill_state_get(): array
    {
        $state = get_option($this->backfill_state_option);
        return is_array($state) ? $state : [];
    }

    private function backfill_state_set(array $state): void
    {
        update_option($this->backfill_state_option, $state, false);
    }

    private function backfill_schedule_next(int $delay_seconds = 5): void
    {
        if (!wp_next_scheduled($this->backfill_cron_hook)) {
            wp_schedule_single_event(time() + max(1, $delay_seconds), $this->backfill_cron_hook);
        }
    }

    private function start_backfill_job(): void
    {
        delete_transient($this->backfill_log_transient);

        $state = [
            'status' => 'running',
            'step' => 'researchers',
            'cpt_paged' => 1,
            'cpt_per' => 50,
            'stocks_offset' => 0,
            'stocks_per' => 200,
            'started_at' => time(),
            'updated_at' => time(),
        ];
        $this->backfill_state_set($state);

        $this->backfill_log_append('▶ Backfill queued…');
        $this->backfill_schedule_next(1);
    }

    public function cron_process_backfill(): void
    {
        if (get_transient($this->backfill_lock_transient)) {
            $this->backfill_schedule_next(10);
            return;
        }

        set_transient($this->backfill_lock_transient, 1, MINUTE_IN_SECONDS * 5);

        try {
            $state = $this->backfill_state_get();
            if (($state['status'] ?? '') !== 'running') {
                return;
            }

            $deadline = time() + 20;

            while (time() < $deadline && (($state['status'] ?? '') === 'running')) {
                $step = $state['step'] ?? 'researchers';

                if ($step === 'researchers') {
                    $res = $this->backfill_cpt_batch($this->cpt_company, function ($p) {
                        $this->on_save_company($p->ID, $p, true);
                    }, (int)$state['cpt_paged'], (int)$state['cpt_per']);

                    $this->backfill_log_append("Researchers batch: {$res['processed']} (page {$state['cpt_paged']})");

                    if ($res['has_more']) {
                        $state['cpt_paged'] = (int)$state['cpt_paged'] + 1;
                    } else {
                        $state['step'] = 'stocks';
                        $state['cpt_paged'] = 1;
                        $this->backfill_log_append('✔ Backfill Researchers complete.');
                    }
                } elseif ($step === 'stocks') {
                    $res = $this->backfill_stocks_batch((int)$state['stocks_offset'], (int)$state['stocks_per']);
                    $this->backfill_log_append("Stocks batch: {$res['processed']} (offset {$state['stocks_offset']})");

                    if ($res['has_more']) {
                        $state['stocks_offset'] = (int)$state['stocks_offset'] + (int)$state['stocks_per'];
                    } else {
                        $state['step'] = 'reports';
                        $state['cpt_paged'] = 1;
                        $this->backfill_log_append('✔ Backfill Stocks complete.');
                    }
                } else {
                    $res = $this->backfill_cpt_batch($this->cpt_report, function ($p) {
                        $this->on_save_report($p->ID, $p, true);
                    }, (int)$state['cpt_paged'], (int)$state['cpt_per']);

                    $this->backfill_log_append("Reports batch: {$res['processed']} (page {$state['cpt_paged']})");

                    if ($res['has_more']) {
                        $state['cpt_paged'] = (int)$state['cpt_paged'] + 1;
                    } else {
                        $state['status'] = 'done';
                        $state['finished_at'] = time();
                        $this->backfill_log_append('✔ Backfill Reports complete.');
                        $this->backfill_log_append('✅ Backfill finished.');
                    }
                }

                $state['updated_at'] = time();
                $this->backfill_state_set($state);
            }

            if (($state['status'] ?? '') === 'running') {
                $this->backfill_schedule_next(5);
            }
        } catch (\Throwable $e) {
            $state = $this->backfill_state_get();
            $state['status'] = 'fail';
            $state['error'] = $e->getMessage();
            $state['updated_at'] = time();
            $this->backfill_state_set($state);
            $this->backfill_log_append('   [ERR] ' . $e->getMessage());
        } finally {
            delete_transient($this->backfill_lock_transient);
        }
    }

    public function handle_run_functions_submit()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 'Unauthorized', 403);
        }
        check_admin_referer('activ8_run_functions');

        $this->start_backfill_job();

        $url = add_query_arg('activ8_backfill_status', 'queued', admin_url('admin.php?page=stock-data-plugin'));
        wp_safe_redirect($url);
        exit;
    }
}

SDP_PG::i();
