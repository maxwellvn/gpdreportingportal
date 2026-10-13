<?php
/**
 * Statistics and Analytics Helper Functions
 * Provides accurate data calculations and validation for admin dashboard
 */

class Statistics {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get comprehensive report statistics with filters
     */
    public function getReportStatistics($filters = []) {
        $whereClause = "WHERE 1=1";
        $params = [];
        
        // Apply filters
        if (!empty($filters['month'])) {
            $whereClause .= " AND DATE_FORMAT(r.report_month, '%Y-%m') = :month";
            $params[':month'] = $filters['month'];
        }
        
        if (!empty($filters['region'])) {
            $whereClause .= " AND u.region = :region";
            $params[':region'] = $filters['region'];
        }
        
        if (!empty($filters['year'])) {
            $whereClause .= " AND YEAR(r.report_month) = :year";
            $params[':year'] = $filters['year'];
        }
        
        if (!empty($filters['zone'])) {
            $whereClause .= " AND u.zone = :zone";
            $params[':zone'] = $filters['zone'];
        }
        
        // Date range filter
        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'last_30_days':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'last_90_days':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                    break;
                case 'last_6_months':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
                    break;
                case 'last_12_months':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
                    break;
                case 'current_year':
                    $whereClause .= " AND YEAR(r.report_month) = YEAR(CURDATE())";
                    break;
                case 'previous_year':
                    $whereClause .= " AND YEAR(r.report_month) = YEAR(CURDATE()) - 1";
                    break;
            }
        }

        $this->applyReportMonthDateRange($whereClause, $params, $filters, 'r');

        // Copies range filter
        if (!empty($filters['min_copies'])) {
            $whereClause .= " AND r.total_copies >= :min_copies";
            $params[':min_copies'] = $filters['min_copies'];
        }
        
        if (!empty($filters['max_copies'])) {
            $whereClause .= " AND r.total_copies <= :max_copies";
            $params[':max_copies'] = $filters['max_copies'];
        }
        
        // Souls range filter
        if (!empty($filters['min_souls'])) {
            $whereClause .= " AND r.souls_won >= :min_souls";
            $params[':min_souls'] = $filters['min_souls'];
        }
        
        // Get comprehensive statistics
        $query = "SELECT 
            COUNT(DISTINCT r.id) as total_reports,
            COUNT(DISTINCT u.zone) as unique_zones,
            COUNT(DISTINCT u.region) as unique_regions,
            COUNT(DISTINCT r.user_id) as unique_users,
            SUM(r.total_copies) as total_copies,
            SUM(r.total_distribution) as total_distribution,
            SUM(r.souls_won) as total_souls,
            SUM(r.rhapsody_outreaches) as total_outreaches,
            SUM(r.rhapsody_cells) as total_rhapsody_cells,
            SUM(r.new_churches) as total_new_churches,
            SUM(r.new_partners) as total_new_partners,
            AVG(r.total_copies) as avg_copies_per_report,
            AVG(r.souls_won) as avg_souls_per_report,
            MIN(r.created_at) as first_submission,
            MAX(r.created_at) as last_submission
            FROM reports r 
            LEFT JOIN users u ON r.user_id = u.id 
            $whereClause";
            
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Add validation and safe calculations
        if ($result) {
            // Prevent division by zero in averages
            $result['avg_copies_per_report'] = $result['total_reports'] > 0 ? $result['total_copies'] / $result['total_reports'] : 0;
            $result['avg_souls_per_report'] = $result['total_reports'] > 0 ? $result['total_souls'] / $result['total_reports'] : 0;

            // Ensure numeric values are properly formatted
            $result['total_reports'] = (int)($result['total_reports'] ?? 0);
            $result['total_copies'] = (int)($result['total_copies'] ?? 0);
            $result['total_souls'] = (int)($result['total_souls'] ?? 0);
            $result['total_distribution'] = (int)($result['total_distribution'] ?? 0);
            $result['total_rhapsody_cells'] = (int)($result['total_rhapsody_cells'] ?? 0);
            $result['total_new_churches'] = (int)($result['total_new_churches'] ?? 0);
            $result['unique_users'] = (int)($result['unique_users'] ?? 0);
            $result['active_users'] = $result['unique_users'];
            $result['unique_zones'] = (int)($result['unique_zones'] ?? 0);
            $result['unique_regions'] = (int)($result['unique_regions'] ?? 0);
        }

        return $result;
    }
    
    /**
     * Get zone compliance data with detailed metrics
     */
    public function getZoneCompliance($filters = []) {
        // Load the canonical API hierarchy through the shared cache/fallback layer.
        $zonesData = getRegionsZones();
        
        $whereClause = "WHERE 1=1";
        $params = [];
        
        // Apply filters
        if (!empty($filters['month'])) {
            $whereClause .= " AND DATE_FORMAT(r.report_month, '%Y-%m') = :month";
            $params[':month'] = $filters['month'];
        }
        
        if (!empty($filters['region'])) {
            $whereClause .= " AND u.region = :region";
            $params[':region'] = $filters['region'];
        }
        
        if (!empty($filters['year'])) {
            $whereClause .= " AND YEAR(r.report_month) = :year";
            $params[':year'] = $filters['year'];
        }

        // Date range filter
        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'last_30_days':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'last_90_days':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                    break;
                case 'last_6_months':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
                    break;
                case 'last_12_months':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
                    break;
                case 'current_year':
                    $whereClause .= " AND YEAR(r.report_month) = YEAR(CURDATE())";
                    break;
                case 'previous_year':
                    $whereClause .= " AND YEAR(r.report_month) = YEAR(CURDATE()) - 1";
                    break;
            }
        }

        $this->applyReportMonthDateRange($whereClause, $params, $filters, 'r');

        // Get zones with reports
        $zonesWithReportsQuery = "SELECT DISTINCT u.zone, u.region
            FROM reports r 
            LEFT JOIN users u ON r.user_id = u.id 
            $whereClause AND u.zone IS NOT NULL";
            
        $stmt = $this->conn->prepare($zonesWithReportsQuery);
        $stmt->execute($params);
        $zonesWithReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate total zones based on filters
        $totalZones = 0;
        $targetRegion = $filters['region'] ?? null;
        
        foreach ($zonesData as $region => $zones) {
            if (!$targetRegion || $region === $targetRegion) {
                $totalZones += count($zones);
            }
        }
        
        // Validate compliance calculation
        if ($totalZones <= 0) {
            $complianceRate = 0;
        } else {
            $compliantCount = count($zonesWithReports);
            // Ensure we don't exceed 100% compliance
            $complianceRate = min(($compliantCount / $totalZones) * 100, 100.0);
            // Round to 1 decimal place for clean display
            $complianceRate = round($complianceRate, 1);
        }

        return [
            'total_zones' => $totalZones,
            'compliant_zones' => count($zonesWithReports),
            'compliance_rate' => $complianceRate,
            'zones_with_reports' => $zonesWithReports
        ];
    }
    
    /**
     * Get monthly submission trends
     */
    public function getMonthlyTrends($months = 12, $filters = []) {
        $whereClause = "WHERE r.report_month >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)";
        $params = [];
        
        if (!empty($filters['region'])) {
            $whereClause .= " AND u.region = :region";
            $params[':region'] = $filters['region'];
        }
        
        $query = "SELECT 
            DATE_FORMAT(r.report_month, '%Y-%m') as month,
            DATE_FORMAT(r.report_month, '%M %Y') as month_label,
            COUNT(DISTINCT r.id) as report_count,
            COUNT(DISTINCT u.zone) as unique_zones,
            SUM(r.total_copies) as total_copies,
            SUM(r.souls_won) as total_souls,
            AVG(r.total_copies) as avg_copies
            FROM reports r 
            LEFT JOIN users u ON r.user_id = u.id 
            $whereClause
            GROUP BY DATE_FORMAT(r.report_month, '%Y-%m')
            ORDER BY r.report_month DESC";
            
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get top performing zones
     */
    public function getTopZones($limit = 10, $filters = []) {
        $whereClause = "WHERE 1=1";
        $params = [];
        
        // Apply all filters
        $whereClause .= $this->buildFilterClause($filters, $params);
        
        // Determine sort order
        $sortBy = $filters['sort_by'] ?? 'total_copies';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        
        // Validate sort fields
        $validSortFields = ['total_copies', 'total_souls', 'report_count', 'avg_copies', 'last_submission', 'active_months'];
        if (!in_array($sortBy, $validSortFields)) {
            $sortBy = 'total_copies';
        }
        
        $query = "SELECT 
            u.zone,
            u.region,
            COUNT(DISTINCT r.id) as report_count,
            SUM(r.total_copies) as total_copies,
            SUM(r.souls_won) as total_souls,
            SUM(r.total_distribution) as total_distribution,
            AVG(r.total_copies) as avg_copies,
            MAX(r.created_at) as last_submission,
            COUNT(DISTINCT DATE_FORMAT(r.report_month, '%Y-%m')) as active_months
            FROM reports r 
            LEFT JOIN users u ON r.user_id = u.id 
            $whereClause AND u.zone IS NOT NULL
            GROUP BY u.zone, u.region
            ORDER BY $sortBy $sortOrder, report_count DESC
            LIMIT $limit";
            
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Build filter clause for queries
     */
    private function buildFilterClause($filters, &$params) {
        $clause = "";
        
        if (!empty($filters['month'])) {
            $clause .= " AND DATE_FORMAT(r.report_month, '%Y-%m') = :month";
            $params[':month'] = $filters['month'];
        }
        
        if (!empty($filters['region'])) {
            $clause .= " AND u.region = :region";
            $params[':region'] = $filters['region'];
        }
        
        if (!empty($filters['year'])) {
            $clause .= " AND YEAR(r.report_month) = :year";
            $params[':year'] = $filters['year'];
        }
        
        if (!empty($filters['zone'])) {
            $clause .= " AND u.zone = :zone";
            $params[':zone'] = $filters['zone'];
        }
        
        // Date range filter
        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'last_30_days':
                    $clause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'last_90_days':
                    $clause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                    break;
                case 'last_6_months':
                    $clause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
                    break;
                case 'last_12_months':
                    $clause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
                    break;
                case 'current_year':
                    $clause .= " AND YEAR(r.report_month) = YEAR(CURDATE())";
                    break;
                case 'previous_year':
                    $clause .= " AND YEAR(r.report_month) = YEAR(CURDATE()) - 1";
                    break;
            }
        }
        
        $this->applyReportMonthDateRange($clause, $params, $filters, 'r');

        // Copies range filter
        if (!empty($filters['min_copies'])) {
            $clause .= " AND r.total_copies >= :min_copies";
            $params[':min_copies'] = $filters['min_copies'];
        }
        
        if (!empty($filters['max_copies'])) {
            $clause .= " AND r.total_copies <= :max_copies";
            $params[':max_copies'] = $filters['max_copies'];
        }
        
        // Souls range filter
        if (!empty($filters['min_souls'])) {
            $clause .= " AND r.souls_won >= :min_souls";
            $params[':min_souls'] = $filters['min_souls'];
        }
        
        return $clause;
    }
    
    /**
     * Get zones filtered by compliance status
     */
    public function getZonesByComplianceStatus($filters = []) {
        $whereClause = "WHERE 1=1";
        $params = [];
        
        // Apply basic filters
        $whereClause .= $this->buildFilterClause($filters, $params);
        
        if (!empty($filters['compliance_status'])) {
            if ($filters['compliance_status'] === 'compliant') {
                // Only zones with reports
                $query = "SELECT DISTINCT
                    u.zone,
                    u.region,
                    COUNT(DISTINCT r.id) as report_count,
                    SUM(r.total_copies) as total_copies,
                    SUM(r.souls_won) as total_souls,
                    MAX(r.created_at) as last_submission
                    FROM reports r 
                    LEFT JOIN users u ON r.user_id = u.id 
                    $whereClause AND u.zone IS NOT NULL
                    GROUP BY u.zone, u.region
                    HAVING report_count > 0
                    ORDER BY total_copies DESC";
            } else {
                // Only zones without reports
                $regionFilter = "";
                if (!empty($filters['region'])) {
                    $regionFilter = " AND u.region = :region_filter";
                    $params[':region_filter'] = $filters['region'];
                }
                
                $query = "SELECT DISTINCT u.zone, u.region, 0 as report_count, 0 as total_copies, 0 as total_souls, NULL as last_submission
                    FROM users u 
                    WHERE u.zone IS NOT NULL $regionFilter
                    AND u.zone NOT IN (
                        SELECT DISTINCT u2.zone 
                        FROM reports r2 
                        LEFT JOIN users u2 ON r2.user_id = u2.id 
                        $whereClause AND u2.zone IS NOT NULL
                    )
                    ORDER BY u.region, u.zone";
            }
        } else {
            // All zones
            $query = "SELECT 
                u.zone,
                u.region,
                COUNT(DISTINCT r.id) as report_count,
                SUM(r.total_copies) as total_copies,
                SUM(r.souls_won) as total_souls,
                MAX(r.created_at) as last_submission
                FROM users u
                LEFT JOIN reports r ON u.id = r.user_id $whereClause
                WHERE u.zone IS NOT NULL
                " . (empty($filters['region']) ? "" : " AND u.region = :region_filter") . "
                GROUP BY u.zone, u.region
                ORDER BY total_copies DESC";
                
            if (!empty($filters['region'])) {
                $params[':region_filter'] = $filters['region'];
            }
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get non-compliant zones (zones without reports)
     */
    public function getNonCompliantZones($filters = []) {
        $whereClause = "WHERE 1=1";
        $params = [];
        
        // Apply filters for the subquery
        if (!empty($filters['month'])) {
            $whereClause .= " AND DATE_FORMAT(r2.report_month, '%Y-%m') = :month";
            $params[':month'] = $filters['month'];
        }
        
        if (!empty($filters['year'])) {
            $whereClause .= " AND YEAR(r2.report_month) = :year";
            $params[':year'] = $filters['year'];
        }

        // Date range filter
        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'last_30_days':
                    $whereClause .= " AND r2.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'last_90_days':
                    $whereClause .= " AND r2.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                    break;
                case 'last_6_months':
                    $whereClause .= " AND r2.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
                    break;
                case 'last_12_months':
                    $whereClause .= " AND r2.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
                    break;
                case 'current_year':
                    $whereClause .= " AND YEAR(r2.report_month) = YEAR(CURDATE())";
                    break;
                case 'previous_year':
                    $whereClause .= " AND YEAR(r2.report_month) = YEAR(CURDATE()) - 1";
                    break;
            }
        }

        $this->applyReportMonthDateRange($whereClause, $params, $filters, 'r2');

        $regionFilter = "";
        if (!empty($filters['region'])) {
            $regionFilter = " AND u.region = :region";
            $params[':region'] = $filters['region'];
        }
        
        $query = "SELECT DISTINCT u.zone, u.region, u.name as user_name
            FROM users u 
            WHERE u.zone IS NOT NULL $regionFilter
            AND u.zone NOT IN (
                SELECT DISTINCT u2.zone 
                FROM reports r2 
                LEFT JOIN users u2 ON r2.user_id = u2.id 
                $whereClause AND u2.zone IS NOT NULL
            )
            ORDER BY u.region, u.zone";
            
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Validate data integrity and return any issues found
     */
    public function validateDataIntegrity() {
        $issues = [];
        
        // Check for reports without users
        $orphanReportsQuery = "SELECT COUNT(*) as count FROM reports r 
                              LEFT JOIN users u ON r.user_id = u.id 
                              WHERE u.id IS NULL";
        $result = $this->conn->query($orphanReportsQuery)->fetch();
        if ($result['count'] > 0) {
            $issues[] = "Found {$result['count']} reports without valid users";
        }
        
        // Check for negative values
        $negativeValuesQuery = "SELECT COUNT(*) as count FROM reports
                               WHERE total_copies < 0
                                  OR souls_won < 0
                                  OR total_distribution < 0
                                  OR distribution_digital < 0
                                  OR distribution_physical < 0
                                  OR crusades < 0
                                  OR testimonies_received < 0";
        $result = $this->conn->query($negativeValuesQuery)->fetch();
        if ($result['count'] > 0) {
            $issues[] = "Found {$result['count']} reports with negative values";
        }
        
        // Check for users without zones
        $usersWithoutZonesQuery = "SELECT COUNT(*) as count FROM users WHERE zone IS NULL OR zone = ''";
        $result = $this->conn->query($usersWithoutZonesQuery)->fetch();
        if ($result['count'] > 0) {
            $issues[] = "Found {$result['count']} users without assigned zones";
        }
        
        // Check for future report dates
        $futureReportsQuery = "SELECT COUNT(*) as count FROM reports WHERE report_month > CURDATE()";
        $result = $this->conn->query($futureReportsQuery)->fetch();
        if ($result['count'] > 0) {
            $issues[] = "Found {$result['count']} reports with future dates";
        }
        
        return $issues;
    }
    
    /**
     * Get submission rate analysis
     */
    public function getSubmissionRateAnalysis($filters = []) {
        $whereClause = "WHERE 1=1";
        $params = [];

        if (!empty($filters['region'])) {
            $whereClause .= " AND u.region = :region";
            $params[':region'] = $filters['region'];
        }

        // Get submission frequency by zone
        $query = "SELECT
            u.zone,
            u.region,
            COUNT(DISTINCT r.id) as total_submissions,
            COUNT(DISTINCT DATE_FORMAT(r.report_month, '%Y-%m')) as unique_months,
            DATEDIFF(MAX(r.report_month), MIN(r.report_month)) / 30.44 as months_span,
            COUNT(DISTINCT r.id) / NULLIF(COUNT(DISTINCT DATE_FORMAT(r.report_month, '%Y-%m')), 0) as avg_reports_per_month,
            DATEDIFF(CURDATE(), MAX(r.created_at)) as days_since_last_report
            FROM reports r
            LEFT JOIN users u ON r.user_id = u.id
            $whereClause AND u.zone IS NOT NULL
            GROUP BY u.zone, u.region
            HAVING total_submissions > 0
            ORDER BY avg_reports_per_month DESC, total_submissions DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get detailed monthly report submission distribution
     */
    public function getSubmissionDistribution($filters = []) {
        $whereClause = "WHERE 1=1";
        $params = [];

        // Apply filters
        if (!empty($filters['region'])) {
            $whereClause .= " AND u.region = :region";
            $params[':region'] = $filters['region'];
        }

        if (!empty($filters['zone'])) {
            $whereClause .= " AND u.zone = :zone";
            $params[':zone'] = $filters['zone'];
        }

        // Date range filter
        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'last_30_days':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'last_90_days':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                    break;
                case 'last_6_months':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
                    break;
                case 'last_12_months':
                    $whereClause .= " AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
                    break;
                case 'current_year':
                    $whereClause .= " AND YEAR(r.created_at) = YEAR(CURDATE())";
                    break;
                case 'previous_year':
                    $whereClause .= " AND YEAR(r.created_at) = YEAR(CURDATE()) - 1";
                    break;
            }
        }

        // Get detailed monthly distribution with additional metrics
        $monthlyQuery = "SELECT
            DATE_FORMAT(r.created_at, '%Y-%m') as month,
            DATE_FORMAT(r.created_at, '%M %Y') as month_label,
            DATE_FORMAT(r.created_at, '%Y') as year,
            COUNT(*) as submissions,
            COUNT(DISTINCT r.user_id) as unique_users,
            COUNT(DISTINCT u.zone) as active_zones,
            ROUND(AVG(r.total_copies), 1) as avg_copies_per_submission,
            ROUND(AVG(r.souls_won), 1) as avg_souls_per_submission,
            MIN(r.created_at) as first_submission,
            MAX(r.created_at) as last_submission
            FROM reports r
            LEFT JOIN users u ON r.user_id = u.id
            $whereClause
            GROUP BY DATE_FORMAT(r.created_at, '%Y-%m'), DATE_FORMAT(r.created_at, '%M %Y'), DATE_FORMAT(r.created_at, '%Y')
            ORDER BY DATE_FORMAT(r.created_at, '%Y-%m') DESC
            LIMIT 24"; // Increased to 24 months for better trend analysis

        $stmt = $this->conn->prepare($monthlyQuery);
        $stmt->execute($params);
        $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total submissions count for the period
        $totalQuery = "SELECT
            COUNT(*) as total_submissions,
            COUNT(DISTINCT r.user_id) as total_unique_users,
            COUNT(DISTINCT u.zone) as total_active_zones,
            ROUND(AVG(r.total_copies), 1) as overall_avg_copies,
            ROUND(AVG(r.souls_won), 1) as overall_avg_souls
            FROM reports r
            LEFT JOIN users u ON r.user_id = u.id
            $whereClause";
        $stmt = $this->conn->prepare($totalQuery);
        $stmt->execute($params);
        $totalResult = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate percentages for monthly data
        $totalSubmissions = $totalResult['total_submissions'] ?? 0;
        if ($totalSubmissions > 0) {
            foreach ($monthlyData as &$month) {
                $month['percentage'] = round(($month['submissions'] * 100.0) / $totalSubmissions, 2);
            }
        }

        return [
            'monthly' => array_reverse($monthlyData), // Reverse to show chronological order
            'total_submissions' => $totalResult['total_submissions'] ?? 0,
            'total_unique_users' => $totalResult['total_unique_users'] ?? 0,
            'total_active_zones' => $totalResult['total_active_zones'] ?? 0,
            'overall_avg_copies' => $totalResult['overall_avg_copies'] ?? 0,
            'overall_avg_souls' => $totalResult['overall_avg_souls'] ?? 0
        ];
    }

    private function normalizeFilterDate($value, $boundary = 'start') {
        if (empty($value) || !is_string($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $boundary === 'end'
                ? date('Y-m-t', strtotime($value . '-01'))
                : $value . '-01';
        }

        return null;
    }

    private function applyReportMonthDateRange(&$whereClause, &$params, $filters, $alias = 'r') {
        $startDate = $this->normalizeFilterDate($filters['date_from'] ?? '', 'start');
        if (!empty($startDate)) {
            $whereClause .= " AND DATE({$alias}.report_month) >= :date_from";
            $params[':date_from'] = $startDate;
        }

        $endDate = $this->normalizeFilterDate($filters['date_to'] ?? '', 'end');
        if (!empty($endDate)) {
            $whereClause .= " AND DATE({$alias}.report_month) <= :date_to";
            $params[':date_to'] = $endDate;
        }
    }
}
?>
