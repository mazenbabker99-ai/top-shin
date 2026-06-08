<?php
// صفحة لتوليد هاش bcrypt لكلمة المرور
$password = 'password';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// التحقق من الهاش
$verify = password_verify($password, $hash);

echo "<h2>توليد هاش bcrypt لكلمة المرور</h2>";
echo "<p><strong>كلمة المرور:</strong> $password</p>";
echo "<p><strong>الهاش:</strong> <code style='background:#f0f0f0;padding:5px;'>$hash</code></p>";
echo "<p><strong>التحقق:</strong> " . ($verify ? '✅ صحيح' : '❌ خطأ') . "</p>";
echo "<hr>";
echo "<p><strong>أمر SQL للتحديث:</strong></p>";
echo "<textarea style='width:100%;height:80px;font-family:monospace;'>UPDATE users SET password = '$hash' WHERE username = 'admin';</textarea>";
