s<?php
// ============================================================
// المسار: config/database.php
// الوظيفة: اتصال قاعدة البيانات — Singleton Pattern مع PDO
// ============================================================

declare(strict_types=1);

// ——— إعدادات الاتصال ———
// تقرأ تلقائياً من متغيرات Railway — لا تعدّل هذه الأسطر
define('DB_HOST',    getenv('MYSQLHOST')     ?: 'localhost');
define('DB_NAME',    getenv('MYSQLDATABASE') ?: 'topshine_db');
define('DB_USER',    getenv('MYSQLUSER')     ?: 'root');
define('DB_PASS',    getenv('MYSQLPASSWORD') ?: '');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT',    getenv('MYSQLPORT')     ?: '3306');

// ============================================================
// class Database — Singleton
// ============================================================
class Database
{
    /** @var Database|null النسخة الوحيدة من الـ Class */
    private static ?Database $instance = null;

    /** @var PDO اتصال PDO */
    private PDO $pdo;

    /**
     * Constructor خاص — يمنع الإنشاء المباشر
     *
     * @throws RuntimeException إذا فشل الاتصال
     */
    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci,
                                             time_zone = '+03:00'",
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // سجّل الخطأ التقني في السيرفر فقط — لا تعرضه للمستخدم
            error_log('[TopShine DB Error] ' . $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine());

            // رسالة عربية للمستخدم بدون تفاصيل تقنية
            $this->showConnectionError();
        }
    }

    /**
     * يمنع النسخ
     */
    private function __clone() {}

    /**
     * يمنع الـ unserialize
     *
     * @throws RuntimeException
     */
    public function __wakeup(): void
    {
        throw new RuntimeException('لا يمكن إنشاء نسخة من الاتصال بهذه الطريقة.');
    }

    // ——— getInstance ———————————————————————————————————————
    /**
     * يُرجع النسخة الوحيدة من Database
     *
     * @return Database
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ——— getConnection ————————————————————————————————————
    /**
     * يُرجع اتصال PDO
     *
     * @return PDO
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    // ——— beginTransaction / commit / rollback —————————————
    /**
     * بدء Transaction
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * تأكيد Transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * التراجع عن Transaction
     */
    public function rollback(): bool
    {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return false;
    }

    /**
     * هل نحن داخل Transaction حالياً؟
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * يُرجع آخر INSERT ID
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // ——— showConnectionError ——————————————————————————————
    /**
     * يعرض رسالة خطأ الاتصال ويوقف التنفيذ
     */
    private function showConnectionError(): never
    {
        http_response_code(503);
        // صفحة خطأ بسيطة بدون كشف تفاصيل
        echo '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>خطأ في النظام</title>
    <style>
        body { font-family: "Tajawal", sans-serif; background: #0D0D0D;
               color: #F5F5F5; display:flex; align-items:center;
               justify-content:center; height:100vh; margin:0; }
        .box { background:#1A1A1A; border:1px solid #C9A84C; border-radius:12px;
               padding:40px; text-align:center; max-width:400px; }
        h2 { color:#C9A84C; margin-bottom:12px; }
        p  { color:#B0B3B8; font-size:14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>⚠ تعذّر الاتصال بقاعدة البيانات</h2>
        <p>يرجى التواصل مع مدير النظام.<br>
           <small style="color:#6C757D;">رمز الخطأ: DB_CONN_FAIL</small>
        </p>
    </div>
</body>
</html>';
        exit;
    }
}

// ============================================================
// دالة مساعدة للحصول على اتصال PDO مباشرة
// الاستخدام: $pdo = db();
// ============================================================
function db(): PDO
{
    return Database::getInstance()->getConnection();
}
