<?php
session_start();
include 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows == 1) {
        $user = $res->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['profile_photo'] = $user['profile_photo'];
            header("Location: admin_dashboard.php");
            exit();
        } else {
            $error = "❌ Invalid password.";
        }
    } else {
        $error = "⚠️ User not found.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Diagnoxis Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap + FontAwesome -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body, html {
            height: 100%;
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
        }

        body {
            background: url('assets/diagnoxis/logo/abranex_logo_icon.png') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            position: relative;
        }

        /* Overlay for faint background */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.85);
            z-index: 0;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            border-radius: 12px;
            background-color: #ffffff;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1;
        }

        .login-card h3 {
            text-align: center;
            margin-bottom: 25px;
            color: #007bff;
        }

        .form-group i {
            position: absolute;
            top: 11px;
            left: 12px;
            color: #888;
        }

        .form-control {
            padding-left: 38px;
        }

        .btn-primary {
            width: 100%;
            font-weight: bold;
        }

        .alert {
            font-size: 14px;
        }

        .footer {
            position: absolute;
            bottom: 10px;
            text-align: center;
            z-index: 1;
            font-size: 13px;
            color: #555;
        }

        .footer img {
            height: 70px;
            vertical-align: middle;
            margin-right: 7px;
        }

        @media (max-width: 576px) {
            body {
                background-size: contain;
            }

            .login-card {
                padding: 20px;
            }

            .footer img {
                height: 16px;
            }
        }
    </style>
</head>
<body>

<div class="login-card">
    <h3><i class="fas fa-user-shield"></i> Admin Login</h3>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="form-group position-relative">
            <i class="fas fa-user"></i>
            <input type="text" name="username" class="form-control" placeholder="Username" required>
        </div>

        <div class="form-group position-relative">
            <i class="fas fa-lock"></i>
            <input type="password" name="password" class="form-control" placeholder="Password" required>
        </div>

        <button type="submit" class="btn btn-primary mt-2">
            <i class="fas fa-sign-in-alt"></i> Login
        </button>
    </form>
</div>

<!-- Footer with logo and copyright -->
<div class="footer">
    <img src="assets/diagnoxis/logo/abranex_full_logo1.png" alt="Abranex Logo">
    &copy; <?= date('Y') ?> Diagnoxis Lab by Abranex. All rights reserved.
</div>

</body>
</html>
