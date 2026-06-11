<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskFlow | 管理コンソール ログイン</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="container">
        <!-- ログイン画面 -->
        <div class="login-box">
            <h2>TaskFlow login</h2>
            <?php if ($login_error): ?>
                <div class="alert alert-error"><?php echo h($login_error); ?></div>
            <?php endif; ?>
            <form method="POST" action="admin.php">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="form_action" value="login">
                <div class="form-group">
                    <label for="password">管理者パスワード</label>
                    <input type="password" id="password" name="password" required autofocus>
                </div>
                <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">認証する</button>
            </form>
        </div>
    </div>
</body>
</html>
