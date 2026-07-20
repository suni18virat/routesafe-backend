<?php
$con = mysqli_connect('bzvrotxo7tt4htiiqyj8-mysql.services.clever-cloud.com', 'uubpzisqyra5om4j', '4lfqFcwEaExSMLbb337T', 'bzvrotxo7tt4htiiqyj8', 3306);
if (!$con) { die("Conn failed"); }

mysqli_query($con, "SET FOREIGN_KEY_CHECKS = 0");

$tables = ['complaint', 'support_message', 'user'];
foreach ($tables as $table) {
    if (mysqli_query($con, "TRUNCATE TABLE $table")) {
        echo "Truncated $table\n";
    } else {
        echo "Error truncating $table: " . mysqli_error($con) . "\n";
    }
}

$keepTeamSql = "SELECT id FROM team";
$teamResult = mysqli_query($con, $keepTeamSql);
$teamIds = [];
if ($teamResult) {
    while ($row = mysqli_fetch_assoc($teamResult)) {
        $teamIds[] = (int) $row['id'];
    }
}

if (!empty($teamIds)) {
    mysqli_query($con, "SET FOREIGN_KEY_CHECKS = 0");
    mysqli_query($con, "DELETE FROM team WHERE id NOT IN (" . implode(',', $teamIds) . ")");
    mysqli_query($con, "SET FOREIGN_KEY_CHECKS = 1");
}

mysqli_query($con, "SET FOREIGN_KEY_CHECKS = 1");
mysqli_close($con);
?>
