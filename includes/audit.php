<?php
// ============================================================
// المسار: includes/audit.php
// الوظيفة: تسجيل كل العمليات في جدول audit_logs
// ============================================================

declare(strict_types=1);

class AuditLogger
{
    private PDO $pdo;

    /**
     * @param PDO $pdo اتصال قاعدة البيانات
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ============================================================
    // الدالة الرئيسية
    // ============================================================

    /**
     * يُسجّل عملية في audit_logs
     *
     * @param string      $action       create|update|delete|login|logout|login_failed|login_blocked|adjustment
     * @param string      $table_name   اسم الجدول المتأثر (فارغ للعمليات العامة كالدخول)
     * @param int|null    $record_id    معرف السجل المتأثر
     * @param array|null  $old_data     البيانات القديمة (قبل التعديل)
     * @param array|null  $new_data     البيانات الجديدة (بعد التعديل / عند الإنشاء)
     * @param int|null    $user_id      تجاوز user_id من SESSION (للـ login قبل تعيين SESSION)
     * @param string|null $user_name    تجاوز user_name من SESSION
     * @param int|null    $branch_id    تجاوز branch_id من SESSION
     */
    public function log(
        string  $action,
        string  $table_name  = '',
        ?int    $record_id   = null,
        ?array  $old_data    = null,
        ?array  $new_data    = null,
        ?int    $user_id     = null,
        ?string $user_name   = null,
        ?int    $branch_id   = null
    ): void {
        try {
            // جلب بيانات المستخدم — من المعاملات أولاً ثم من SESSION
            $uid  = $user_id   ?? (int)($_SESSION['user_id']   ?? 0) ?: null;
            $uname = $user_name ?? ($_SESSION['user_name']      ?? null);
            $bid  = $branch_id  ?? (int)($_SESSION['branch_id'] ?? 0) ?: null;

            // IP Address
            $ip = $this->get_client_ip();

            // JSON encoding للبيانات
            $old_json = $old_data !== null ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null;
            $new_json = $new_data !== null ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null;

            $stmt = $this->pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, user_name, branch_id, action, table_name,
                     record_id, old_data, new_data, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $uid,
                $uname,
                $bid,
                $action,
                $table_name,
                $record_id,
                $old_json,
                $new_json,
                $ip,
            ]);

        } catch (Throwable $e) {
            // لا يوقف التنفيذ — يُسجّل فقط في error_log
            error_log(
                '[TopShine AuditLog Error] ' . $e->getMessage()
                . ' | Action: ' . $action
                . ' | Table: ' . $table_name
                . ' | User: ' . ($_SESSION['user_name'] ?? 'unknown')
            );
        }
    }

    // ============================================================
    // دوال مساعدة متخصصة
    // ============================================================

    /**
     * يُسجّل إنشاء سجل جديد
     *
     * @param string $table
     * @param int    $record_id
     * @param array  $data
     */
    public function created(string $table, int $record_id, array $data = []): void
    {
        // نزيل الـ password من الـ log
        $safe_data = $this->strip_sensitive($data);
        $this->log('create', $table, $record_id, null, $safe_data);
    }

    /**
     * يُسجّل تعديل سجل موجود
     *
     * @param string $table
     * @param int    $record_id
     * @param array  $old_data  البيانات قبل التعديل
     * @param array  $new_data  البيانات بعد التعديل
     */
    public function updated(string $table, int $record_id, array $old_data, array $new_data): void
    {
        // سجّل الحقول المتغيرة فقط (لا الكل)
        $changed_old = [];
        $changed_new = [];

        foreach ($new_data as $key => $new_val) {
            $old_val = $old_data[$key] ?? null;
            if ((string)$old_val !== (string)$new_val) {
                $changed_old[$key] = $old_val;
                $changed_new[$key] = $new_val;
            }
        }

        if (empty($changed_new)) return; // لا تغيير

        $this->log(
            'update',
            $table,
            $record_id,
            $this->strip_sensitive($changed_old),
            $this->strip_sensitive($changed_new)
        );
    }

    /**
     * يُسجّل حذف / تعطيل سجل
     *
     * @param string $table
     * @param int    $record_id
     * @param array  $old_data  بيانات السجل قبل الحذف
     */
    public function deleted(string $table, int $record_id, array $old_data = []): void
    {
        $this->log('delete', $table, $record_id, $this->strip_sensitive($old_data), null);
    }

    /**
     * يُسجّل تسجيل دخول ناجح
     *
     * @param int    $user_id
     * @param string $user_name
     * @param int|null $branch_id
     */
    public function login(int $user_id, string $user_name, ?int $branch_id): void
    {
        $this->log(
            'login', 'users', $user_id,
            null, null,
            $user_id, $user_name, $branch_id
        );
    }

    /**
     * يُسجّل تسجيل خروج
     */
    public function logout(): void
    {
        $this->log('logout', 'users', (int)($_SESSION['user_id'] ?? 0));
    }

    /**
     * يُسجّل محاولة دخول فاشلة
     *
     * @param string $username   اسم المستخدم الذي حاول الدخول
     * @param string $reason     سبب الفشل (مثل: wrong_password, not_found, inactive)
     */
    public function login_failed(string $username, string $reason = ''): void
    {
        $this->log(
            'login_failed', 'users', null,
            null,
            ['username' => $username, 'reason' => $reason]
        );
    }

    /**
     * يُسجّل حظر مؤقت بعد محاولات متكررة
     *
     * @param string $username
     * @param int    $attempts  عدد المحاولات
     */
    public function login_blocked(string $username, int $attempts): void
    {
        $this->log(
            'login_blocked', 'users', null,
            null,
            ['username' => $username, 'attempts' => $attempts]
        );
    }

    /**
     * يُسجّل تعديل مخزون يدوي
     *
     * @param int      $product_id
     * @param int|null $branch_id
     * @param float    $old_qty
     * @param float    $new_qty
     * @param string   $reason
     */
    public function stock_adjusted(
        int    $product_id,
        ?int   $branch_id,
        float  $old_qty,
        float  $new_qty,
        string $reason = ''
    ): void {
        $this->log(
            'adjustment', 'inventory', $product_id,
            ['quantity' => $old_qty, 'branch_id' => $branch_id],
            ['quantity' => $new_qty, 'branch_id' => $branch_id, 'reason' => $reason]
        );
    }

    // ============================================================
    // دوال خاصة
    // ============================================================

    /**
     * يجلب IP الحقيقي للعميل مع مراعاة الـ Proxy
     *
     * @return string
     */
    private function get_client_ip(): string
    {
        $keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * يُزيل الحقول الحساسة من البيانات قبل التسجيل
     *
     * @param array $data
     * @return array
     */
    private function strip_sensitive(array $data): array
    {
        $sensitive = ['password', 'passwd', 'pass', 'secret', 'token', 'csrf_token'];
        foreach ($sensitive as $key) {
            if (isset($data[$key])) {
                $data[$key] = '***';
            }
        }
        return $data;
    }
}
