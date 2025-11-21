<?php
if (!defined('ABSPATH')) exit;

add_action('init', 'register_postgres_dashboard_js');

function register_postgres_dashboard_js()
{
    wp_register_script(
        'dashboard-reports-grid',
        plugin_dir_url(__DIR__) . 'assets/js/dashboard-reports-grid.js',
        100,
        true
    );
}

class SDP_PG_Dashboard_Helper
{
    private static $I;
    private $pdo = null;

    private $cpt_company = 'company';   // researcher source (unused here)
    private $cpt_report  = 'report';    // report (unused here)
    private $db_schema   = 'dashboard'; // SCHEMA - localwp or dashboard

    public static function i(): self
    {
        if (!self::$I) self::$I = new self();
        return self::$I;
    }

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
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log("[A8PG] PDO connection failed: " . $e->getMessage());
            throw $e;
        }

        return $this->pdo;
    }

    /**
     * Fetch analytics rows for the grid.
     * @param array $args ['table' => 'reports', 'limit' => 5000, 'orderBy' => '"end_date" DESC, "created_at" DESC']
     * @return array ['total' => int, 'rows' => array[]]
     */
    public function get_reports_rows(array $args = []): array
    {
        $table   = $args['table']   ?? 'researcher_stock_chart'; // adjust if your table differs
        $limit   = (int)($args['limit'] ?? 5000);
        $limit   = max(1, min(50000, $limit));
        $orderBy = $args['orderBy'] ?? '"end_date" DESC, "created_at" DESC';

        $pdo = $this->pdo();

        $qualified = sprintf('"%s"."%s"', $this->db_schema, $table);

        // NOTE: computed columns for change / pct_change added server-side
        $sql = "
      SELECT
        \"id\"::integer                 AS id,
        \"as_of_date\"::date            AS as_of_date,
        \"researcher_name\"::text       AS researcher_name,
        \"return_1d\"::double precision AS return_1d,
        CASE 
          WHEN EXISTS (SELECT 1 
                       FROM information_schema.columns 
                       WHERE table_schema = :schema AND table_name = :table AND column_name = 'created_at')
          THEN \"created_at\"
          ELSE NULL
        END                              AS created_at
      FROM {$qualified}
      ORDER BY {$orderBy}
      LIMIT :limit
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':schema', $this->db_schema, \PDO::PARAM_STR);
        $stmt->bindValue(':table',  $table,           \PDO::PARAM_STR);
        $stmt->bindValue(':limit',  $limit,           \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $total = (int)$pdo->query("SELECT COUNT(*) FROM {$qualified}")->fetchColumn();

        // Normalize types for front-end
        $rows = array_map(function ($r) {
            // strip non-breaking spaces some names may contain
            $name = isset($r['researcher_name']) ? preg_replace('/\x{00A0}/u', ' ', $r['researcher_name']) : null;

            return [
                'id'              => isset($r['id']) ? (int)$r['id'] : null,
                'as_of_date'      => $r['as_of_date'], // 'YYYY-MM-DD'
                'researcher_name' => $name,
                'return_1d'       => isset($r['return_1d']) ? (float)$r['return_1d'] : null,
                'created_at'      => $r['created_at'] ?? null,
            ];
        }, $rows);

        return ['total' => $total, 'rows' => $rows];
    }

    public function get_researcher_stock_chart_rows(array $args = []): array
    {
        $table   = $args['table']   ?? 'researcher_stock_chart';
        $limit   = (int)($args['limit'] ?? 5000);
        $limit   = max(1, min(50000, $limit));
        $orderBy = $args['orderBy'] ?? '"as_of_date" DESC, "researcher_name" ASC, "ticker" ASC';

        $pdo = $this->pdo();
        $qualified = sprintf('"%s"."%s"', $this->db_schema, $table);

        $sql = "
      SELECT
        \"as_of_date\"::date              AS as_of_date,
        \"researcher_name\"::text         AS researcher_name,
        \"ticker\"::text                  AS ticker,
        \"is_down\"::integer              AS is_down,
        \"down_5pct\"::integer            AS down_5pct,
        \"down_10pct\"::integer           AS down_10pct,
        \"down_25pct\"::integer           AS down_25pct,
        \"down_100pct\"::integer          AS down_100pct,
        \"avg_return\"::double precision  AS avg_return,
        \"start_date\"::date              AS start_date,
        \"end_date\"::date                AS end_date,
        \"start_price\"::double precision AS start_price,
        \"end_price\"::double precision   AS end_price,
        \"created_at\"                    AS created_at,
        (\"end_price\" - \"start_price\") AS change_abs,
        CASE
          WHEN \"start_price\" = 0 THEN NULL
          ELSE ((\"end_price\" - \"start_price\") / \"start_price\") * 100
        END                               AS change_pct
      FROM {$qualified}
      ORDER BY {$orderBy}
      LIMIT :limit
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $total = (int)$pdo->query("SELECT COUNT(*) FROM {$qualified}")->fetchColumn();

        // Normalize + clean NBSP in researcher_name
        $rows = array_map(function ($r) {
            $name = isset($r['researcher_name']) ? preg_replace('/\x{00A0}/u', ' ', $r['researcher_name']) : null;
            return [
                'as_of_date'     => $r['as_of_date'],
                'researcher_name' => $name,
                'ticker'         => $r['ticker'],
                'is_down'        => isset($r['is_down']) ? (int)$r['is_down'] : null,
                'down_5pct'      => isset($r['down_5pct']) ? (int)$r['down_5pct'] : null,
                'down_10pct'     => isset($r['down_10pct']) ? (int)$r['down_10pct'] : null,
                'down_25pct'     => isset($r['down_25pct']) ? (int)$r['down_25pct'] : null,
                'down_100pct'    => isset($r['down_100pct']) ? (int)$r['down_100pct'] : null,
                'avg_return'     => isset($r['avg_return']) ? (float)$r['avg_return'] : null, // likely fraction e.g. 0.05
                'start_date'     => $r['start_date'],
                'end_date'       => $r['end_date'],
                'start_price'    => isset($r['start_price']) ? (float)$r['start_price'] : null,
                'end_price'      => isset($r['end_price']) ? (float)$r['end_price'] : null,
                'created_at'     => $r['created_at'],
                'change_abs'     => isset($r['change_abs']) ? (float)$r['change_abs'] : null,
                'change_pct'     => isset($r['change_pct']) ? (float)$r['change_pct'] : null,
            ];
        }, $rows);

        return ['total' => $total, 'rows' => $rows];
    }
}

/**
 * Enqueue AG Grid + your grid script, fetch rows via helper, and localize into JS.
 * Replace the is_page() condition so it only runs where the grid is needed.
 */
add_action('wp_enqueue_scripts', function () {
    // Only load where needed
    // Example: if you’re using a specific page slug like /reports-analytics/
    if (!is_page('dashboard')) return;

    // set sdpAgChartKey
    $sdpAPIManager = new SDP_AG_API_Manager();
    $agChartKey = $sdpAPIManager->get_api_key();
    wp_register_script('sdpAgChartKey', '', [], 100, true);
    wp_enqueue_script('sdpAgChartKey');

    // Fetch data (optionally cache it for performance)
    // ---- Fetch dataset for the grid (with light caching)
    $error   = null;
    $payload = ['total' => 0, 'rows' => []];

    $cache_key = 'a8_researcher_stock_chart_rows_v1';
    if (is_array($c = get_transient($cache_key))) {
        $payload = $c;
    } else {
        try {
            $payload = SDP_PG_Dashboard_Helper::i()->get_researcher_stock_chart_rows([
                'table'   => 'researcher_stock_chart',
                'limit'   => 5000,
                'orderBy' => '"as_of_date" DESC, "researcher_name" ASC, "ticker" ASC',
            ]);
            set_transient($cache_key, $payload, 5 * MINUTE_IN_SECONDS);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    // Your front-end file (place it in your theme at /js/activ8-reports-grid.js)
    wp_enqueue_script('activ8-reports-grid', plugin_dir_url(__DIR__) . 'assets/js/dashboard-reports-grid.js', ['ag-grid'], 100, true);

    // Localize payload for JS
    wp_localize_script('activ8-reports-grid', 'activ8Reports', [
        'total' => $payload['total'] ?? 0,
        'error' => $error,
        'rows'  => $payload['rows'] ?? [],
    ]);
});
