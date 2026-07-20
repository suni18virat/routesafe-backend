<?php
$con = mysqli_connect('bzvrotxo7tt4htiiqyj8-mysql.services.clever-cloud.com', 'uubpzisqyra5om4j', '4lfqFcwEaExSMLbb337T', 'bzvrotxo7tt4htiiqyj8', 3306);
if (!$con) { die("Conn failed"); }
$res = mysqli_query($con, "SHOW TABLES");
while ($row = mysqli_fetch_row($res)) {
    echo $row[0] . "\n";
}
mysqli_close($con);
?>
