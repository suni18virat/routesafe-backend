<?php
include 'db_config.php';
if (!$con) {
    die("Connection failed");
}
$query = "ALTER TABLE support_message ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP";
if (mysqli_query($con, $query)) {
    echo "Column added successfully\n";
} else {
    echo "Error: " . mysqli_error($con) . "\n";
}
mysqli_close($con);
?>
