<?php
// Include your database connection
require 'config.php';

// The password we want to set
$plain_password = 'Admin@123!';

// Hash the password securely
$hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

try {
    // Update the default admin account in the database
    $stmt = $pdo->prepare("UPDATE admins SET password = :password WHERE username = 'admin'");
    $stmt->execute(['password' => $hashed_password]);
    
    echo "<div style='background: #10b981; color: #fff; padding: 20px; font-family: sans-serif; border-radius: 10px; max-width: 400px; margin: 50px auto; text-align: center;'>";
    echo "<h2>✅ Success!</h2>";
    echo "<p>Admin password has been securely updated.</p>";
    echo "<p><strong>Username:</strong> admin<br><strong>Password:</strong> Admin@123!</p>";
    echo "<a href='login.php' style='color: #0b0d14; font-weight: bold; text-decoration: none;'>Click here to go to Login Page</a>";
    echo "</div>";

} catch(PDOException $e) {
    echo "Error updating password: " . $e->getMessage();
}
?>