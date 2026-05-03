<?php
session_start();
require_once 'includes/config.php';
require_once 'vendor/autoload.php';

$googleClient = new Google_Client();
$googleClient->setClientId('YOUR_GOOGLE_CLIENT_ID');
$googleClient->setClientSecret('YOUR_GOOGLE_CLIENT_SECRET');
$googleClient->setRedirectUri('http://yourdomain.com/google-auth-callback.php');
$googleClient->addScope('email');
$googleClient->addScope('profile');

if (isset($_GET['code'])) {
    try {
        $token = $googleClient->fetchAccessTokenWithAuthCode($_GET['code']);
        $googleClient->setAccessToken($token);
        
        $oauth = new Google_Service_Oauth2($googleClient);
        $userInfo = $oauth->userinfo->get();
        
        $email = $userInfo->email;
        $firstName = $userInfo->givenName;
        $lastName = $userInfo->familyName;
        
        // Check if user exists in database
        $stmt = $conn->prepare("SELECT 
            u.user_id, 
            u.username, 
            u.user_type, 
            u.is_verified,
            IFNULL(o.is_approved, 1) as operator_approved
        FROM users u
        LEFT JOIN operators o ON u.user_id = o.user_id
        WHERE u.email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $user = $result->fetch_assoc()) {
            // User exists - log them in
            if ($user['is_verified'] != 1) {
                // Update verification status if not verified
                $updateStmt = $conn->prepare("UPDATE users SET is_verified = 1 WHERE email = ?");
                $updateStmt->bind_param("s", $email);
                $updateStmt->execute();
                $updateStmt->close();
            }
            
            // Check for operator approval
            if ($user['user_type'] === 'operator' && $user['operator_approved'] != 1) {
                $_SESSION['error'] = "Your operator account is pending approval. Please contact administrator.";
                header("Location: login.php");
                exit;
            }
            
            // Successful login
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_type'] = $user['user_type'];
            
            // Redirect by role
            switch ($user['user_type']) {
                case 'admin':
                    header("Location: admin/dashboard.php");
                    exit;
                case 'operator':
                    header("Location: operator/dashboard.php");
                    exit;
                case 'passenger':
                default:
                    header("Location: passenger/dashboard.php");
                    exit;
            }
        } else {
            // User doesn't exist - create new account
            $username = strtolower($firstName . $lastName);
            $tempPassword = bin2hex(random_bytes(8));
            $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            $insertStmt = $conn->prepare("INSERT INTO users (username, email, password_hash, user_type, is_verified) VALUES (?, ?, ?, 'passenger', 1)");
            $insertStmt->bind_param("sss", $username, $email, $passwordHash);
            
            if ($insertStmt->execute()) {
                $user_id = $conn->insert_id;
                
                $_SESSION['loggedin'] = true;
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['user_type'] = 'passenger';
                
                header("Location: passenger/dashboard.php");
                exit;
            } else {
                $_SESSION['error'] = "Error creating account. Please try again or use another login method.";
                header("Location: login.php");
                exit;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Google login failed: " . $e->getMessage();
        header("Location: login.php");
        exit;
    }
} else {
    // No code received - redirect to login
    header("Location: login.php");
    exit;
}
?>