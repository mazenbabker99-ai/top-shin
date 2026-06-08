<?php
// ============================================================
// المسار: includes/auth.php
// الوظيفة: نظام المصادقة — تسجيل الدخول / الخروج / الصلاحيات
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/audit.php';

class Auth
{
    private PDO         $pdo;
    private AuditLogger $audit;

    // ——— إعدادات Rate Limiting ———
    private const MAX_ATTEMPTS    = 5;   // أقصى محاولات قبل الحظر
    private const BLOCK_MINUTES   = 15;  // مدة الحظر بالدقائق
    private const BLOCK_SECONDS   = self::BLOCK_MINUTES * 60;

    /**
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo   = $pdo;
        $this->audit = new AuditLogger($pdo);
    }

    // ============================================================
    // تسجيل الدخول
    // ============================================================

    /**
     * يُحاول تسجيل الدخول
     *
     * @param string $username
     * @param string $password
     * @return array{success: bool, message: string, minutes_left?: int}
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);

        // ——— 1. Rate Limiting Check ———
        $rate_result = $this->check_rate_limit($username);
        if ($rate_result['blocked']) {
            $this->audit->login_blocked($username, $rate_result['attempts']);
            return [
                'success'      => false,
                'message'      => 'blocked',
                'minutes_left' => $rate_result['minutes_left'],
            ];
        }

        // ——— 2. جلب المستخدم من قاعدة البيانات ———
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.username, u.password, u.role,
                    u.lang, u.status, u.branch_id,
                    b.code AS branch_code, b.name AS branch_name
             FROM users u
             LEFT JOIN branches b ON b.id = u.branch_id
             WHERE u.username = ?
             LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // ——— 3. تحقق من وجود المستخدم ———
        if (!$user) {
            $this->increment_attempts($username);
            $this->audit->login_failed($username, 'not_found');
            return ['success' => false, 'message' => 'invalid'];
        }

        // ——— 4. تحقق من الحالة ———
        if ($user['status'] !== 'active') {
            $this->audit->login_failed($username, 'inactive');
            return ['success' => false, 'message' => 'inactive'];
        }

        // ——— 5. تحقق من كلمة المرور ———
        if (!password_verify($password, $user['password'])) {
            $this->increment_attempts($username);
            $this->audit->login_failed($username, 'wrong_password');
            return ['success' => false, 'message' => 'invalid'];
        }

        // ——— 6. نجح الدخول ———

        // مسح سجل المحاولات
        $this->clear_attempts($username);

        // تجديد الـ session لمنع session fixation
        session_regenerate_id(true);

        // تعيين بيانات الـ session
        $_SESSION['user_id']     = (int)  $user['id'];
        $_SESSION['user_name']   = (string) $user['name'];
        $_SESSION['user_role']   = (string) $user['role'];
        $_SESSION['branch_id']   = $user['branch_id'] ? (int) $user['branch_id'] : null;
        $_SESSION['branch_code'] = $user['branch_code'] ?? null;
        $_SESSION['branch_name'] = $user['branch_name'] ?? null;
        $_SESSION['lang']        = in_array($user['lang'], ['ar', 'en']) ? $user['lang'] : 'ar';
        $_SESSION['logged_in']   = true;
        $_SESSION['login_time']  = time();

        // تحديث last_login في قاعدة البيانات
        $update = $this->pdo->prepare(
            "UPDATE users SET last_login = NOW() WHERE id = ?"
        );
        $update->execute([$user['id']]);

        // تسجيل في audit_logs
        $this->audit->login(
            (int) $user['id'],
            (string) $user['name'],
            $user['branch_id'] ? (int) $user['branch_id'] : null
        );

        return ['success' => true, 'message' => 'ok'];
    }

    // ============================================================
    // تسجيل الخروج
    // ============================================================

    /**
     * يُسجّل الخروج ويمسح الـ session
     *
     * @return never
     */
    public function logout(): never
    {
        $this->audit->logout();

        // مسح كامل لبيانات الـ session
        $_SESSION = [];

        // حذف الـ cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();

        // تحديد المسار الصحيح للـ login
        $script_dir = dirname($_SERVER['SCRIPT_NAME']);
        $depth      = substr_count(rtrim($script_dir, '/'), '/');
        $prefix     = str_repeat('../', $depth);

        header('Location: ' . $prefix . 'auth/login.php');
        exit;
    }

    // ============================================================
    // فحص الـ Session (Static)
    // ============================================================

    /**
     * هل المستخدم مسجل دخوله؟
     *
     * @return bool
     */
    public static function check(): bool
    {
        return !empty($_SESSION['logged_in'])
            && !empty($_SESSION['user_id'])
            && !empty($_SESSION['user_role']);
    }

    /**
     * يُرجع دور المستخدم الحالي
     *
     * @return string  super_admin | branch_admin | cashier | ''
     */
    public static function getRole(): string
    {
        return $_SESSION['user_role'] ?? '';
    }

    /**
     * يُرجع معرف فرع المستخدم الحالي
     *
     * @return int|null  null للـ super_admin
     */
    public static function getBranchId(): ?int
    {
        return $_SESSION['branch_id'] ?? null;
    }

    /**
     * يُرجع معرف المستخدم الحالي
     *
     * @return int
     */
    public static function getUserId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    /**
     * يتحقق إن كان الدور ضمن الأدوار المسموح بها
     *
     * @param array<string> $roles
     * @return bool
     */
    public static function hasRole(array $roles): bool
    {
        return in_array(self::getRole(), $roles, true);
    }

    /**
     * يُرجع اللغة الحالية
     *
     * @return string 'ar' | 'en'
     */
    public static function getLang(): string
    {
        return $_SESSION['lang'] ?? 'ar';
    }

    // ============================================================
    // Rate Limiting (Private)
    // ============================================================

    /**
     * يتحقق من حالة Rate Limiting لـ username
     *
     * @param string $username
     * @return array{blocked: bool, attempts: int, minutes_left: int}
     */
    private function check_rate_limit(string $username): array
    {
        $key  = 'login_attempts_' . md5($username);
        $data = $_SESSION[$key] ?? ['count' => 0, 'first_at' => 0, 'blocked_at' => 0];

        // هل محظور؟
        if ($data['blocked_at'] > 0) {
            $elapsed = time() - $data['blocked_at'];
            if ($elapsed < self::BLOCK_SECONDS) {
                $minutes_left = (int) ceil((self::BLOCK_SECONDS - $elapsed) / 60);
                return [
                    'blocked'      => true,
                    'attempts'     => $data['count'],
                    'minutes_left' => $minutes_left,
                ];
            }
            // انتهى الحظر — أعد الضبط
            $this->clear_attempts($username);
        }

        return ['blocked' => false, 'attempts' => $data['count'], 'minutes_left' => 0];
    }

    /**
     * يزيد عداد محاولات تسجيل الدخول الفاشلة
     *
     * @param string $username
     */
    private function increment_attempts(string $username): void
    {
        $key  = 'login_attempts_' . md5($username);
        $data = $_SESSION[$key] ?? ['count' => 0, 'first_at' => time(), 'blocked_at' => 0];

        $data['count']++;

        if ($data['count'] >= self::MAX_ATTEMPTS && $data['blocked_at'] === 0) {
            $data['blocked_at'] = time();
        }

        $_SESSION[$key] = $data;
    }

    /**
     * يمسح سجل المحاولات الفاشلة
     *
     * @param string $username
     */
    private function clear_attempts(string $username): void
    {
        $key = 'login_attempts_' . md5($username);
        unset($_SESSION[$key]);
    }

    // ============================================================
    // Redirect بعد الدخول
    // ============================================================

    /**
     * يُحدد وجهة Redirect حسب دور المستخدم
     *
     * @param string $role
     * @param string $base_path  المسار الجذر للمشروع
     * @return string
     */
    public static function get_redirect_url(string $role, string $base_path = ''): string
    {
        return match($role) {
            'super_admin'  => $base_path . '/superadmin/dashboard.php',
            'branch_admin' => $base_path . '/admin/dashboard.php',
            'cashier'      => $base_path . '/cashier/dashboard.php',
            default        => $base_path . '/auth/login.php',
        };
    }
}
