<?php

if (!defined('ABSPATH')) exit;

class SDP_PG
{
    private static $I;
    private $pdo = null;

    // EDIT THESE to match your site:
    private $cpt_company = 'company';  // researcher source
    private $cpt_report  = 'report';   // reports

    private $db_schema = 'production'; // your target schema

    // ACF field handles on report posts:
    private $acf_researcher_company = 'researcher_company'; // Post Object to 'company' CPT
    private $acf_company_id         = 'company_id';         // int company_id
    private $acf_report_date        = 'report_date';        // 'Y-m-d'

    static function i()
    {
        return self::$I ?: self::$I = new self;
    }
    function __construct()
    {
        add_action("save_post_{$this->cpt_company}", [$this, 'on_save_company'], 10, 3);
        add_action("save_post_{$this->cpt_report}",  [$this, 'on_save_report'], 10, 3);

        if (defined('WP_CLI')) {
            \WP_CLI::add_command('activ8 backfill-researchers', [$this, 'cli_backfill_researchers']);
            \WP_CLI::add_command('activ8 backfill-stocks',      [$this, 'cli_backfill_stocks']);
            \WP_CLI::add_command('activ8 backfill-reports',     [$this, 'cli_backfill_reports']);
        }
    }

    /*** PDO ***/
    private function pdo(): PDO
    {
        if ($this->pdo) return $this->pdo;
        $host = sdp_decrypt_api_key(get_option('sdp_postgres_host'));
        $port = sdp_decrypt_api_key(get_option('sdp_postgres_port'));
        $db   = sdp_decrypt_api_key(get_option('sdp_postgres_db'));
        $schema = sdp_decrypt_api_key(get_option('sdp_postgres_schema'));
        $user = sdp_decrypt_api_key(get_option('sdp_postgres_user'));
        $pass = sdp_decrypt_api_key(get_option('sdp_postgres_password'));
        $ssl  = 'require';
        $dsn  = "pgsql:host={$host};port={$port};dbname={$db};sslmode={$ssl}";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $this->pdo;
    }

    /*** RESEARCHERS: from CPT 'company' ***/
    function on_save_company($post_id, $post, $update)
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if ($post->post_type !== $this->cpt_company) return;

        // researcher_name: prefer post_title; fall back to post_name if you truly want slug
        $researcher_name = get_the_title($post_id) ?: $post->post_name;

        $sql = <<<SQL
            INSERT INTO {$this->db_schema}.researchers (researcher_id, researcher_name, updated_at)
            VALUES (:id, :name, NOW())
            ON CONFLICT (researcher_id) DO UPDATE SET
            researcher_name = EXCLUDED.researcher_name,
            updated_at      = NOW();
            SQL;
        $st = $this->pdo()->prepare($sql);
        $st->execute([':id' => $post_id, ':name' => $researcher_name]);
    }

    /*** STOCK_INFO: combine wp_stock_company_info + wp_stock_tickers ***/
    public function cli_backfill_stocks($args, $assoc)
    {
        global $wpdb;
        $tblCo = $wpdb->prefix . 'stock_company_info';
        $tblTi = $wpdb->prefix . 'stock_tickers';

        // Grab a joined snapshot (adjust column names if needed)
        $rows = $wpdb->get_results("
      SELECT c.company_id, c.company_name, c.industry, c.sector,
             MAX(t.symbol) AS ticker
      FROM {$tblCo} c
      LEFT JOIN {$tblTi} t ON t.company_id = c.company_id
      GROUP BY c.company_id, c.company_name, c.industry, c.sector
      ORDER BY c.company_id ASC
    ", ARRAY_A);

        if (defined('WP_CLI')) $bar = \WP_CLI\Utils\make_progress_bar('Backfilling stock_info', count($rows));

        $sql = <<<SQL
            INSERT INTO {$this->db_schema}.stock_info (company_id, ticker, company_name, industry, sector, updated_at)
            VALUES (:id, :ticker, :name, :industry, :sector, NOW())
            ON CONFLICT (company_id) DO UPDATE SET
            ticker       = EXCLUDED.ticker,
            company_name = EXCLUDED.company_name,
            industry     = EXCLUDED.industry,
            sector       = EXCLUDED.sector,
            updated_at   = NOW();
            SQL;
        $st = $this->pdo()->prepare($sql);

        foreach ($rows as $r) {
            $st->execute([
                ':id'      => (int)$r['company_id'],
                ':ticker'  => $r['ticker'] ?: null,
                ':name'    => $r['company_name'] ?: '',
                ':industry' => $r['industry'] ?: null,
                ':sector'  => $r['sector'] ?: null,
            ]);
            if (isset($bar)) $bar->tick();
        }
        if (isset($bar)) {
            $bar->finish();
            \WP_CLI::success('stock_info backfill complete.');
        }
    }

    /*** RESEARCHERS backfill: all 'company' posts ***/
    public function cli_backfill_researchers($args, $assoc)
    {
        $this->backfill_cpt($this->cpt_company, function ($p) {
            $this->on_save_company($p->ID, $p, true);
        });
    }

    /*** REPORTS: from CPT 'report' ***/
    public function on_save_report($post_id, $post, $update)
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if ($post->post_type !== $this->cpt_report) return;

        // ACF pulls
        $researcher_id = (int) get_post_meta($post_id, $this->acf_researcher_company, true);
        $company_id    = (int) get_post_meta($post_id, $this->acf_company_id, true);
        $report_date   = get_post_meta($post_id, $this->acf_report_date, true); // 'Y-m-d'

        if (!$researcher_id || !$company_id || !$report_date) {
            // Soft fail—missing minimal keys
            error_log("[A8PG] reports upsert skipped (missing keys) for post {$post_id}");
            return;
        }

        $sql = <<<SQL
INSERT INTO {$this->db_schema}.reports (researcher_id, company_id, reportdts, reportprice_open, reportprice_close, reportprice_prior)
VALUES (:rid, :cid, :d, NULL, NULL, NULL)
ON CONFLICT (researcher_id, company_id, reportdts) DO NOTHING;
SQL;
        $st = $this->pdo()->prepare($sql);
        $st->execute([
            ':rid' => $researcher_id,
            ':cid' => $company_id,
            ':d'   => $report_date,
        ]);
    }

    /*** REPORTS backfill: all 'report' posts ***/
    public function cli_backfill_reports($args, $assoc)
    {
        $this->backfill_cpt($this->cpt_report, function ($p) {
            $this->on_save_report($p->ID, $p, true);
        });
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
        if (defined('WP_CLI')) $bar = \WP_CLI\Utils\make_progress_bar("Backfilling {$cpt}", (int)$q->found_posts);
        while ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $fn($p);
                if (isset($bar)) $bar->tick();
            }
            $paged++;
            $q = new WP_Query(['post_type' => $cpt, 'post_status' => 'any', 'posts_per_page' => $per, 'paged' => $paged, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => false]);
        }
        if (isset($bar)) {
            $bar->finish();
            \WP_CLI::success("{$cpt} backfill complete.");
        }
    }
}

SDP_PG::i();
