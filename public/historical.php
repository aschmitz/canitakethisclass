<?php
$use_highcharts = true;
include "../templates/header.php";
include "../templates/connect_mysql.php";
include "../templates/analyze.php";

$q = $_GET["q"];
$sem = $_GET["semester"];

$semesters_offered = array();
$start_date = NULL;
$instruction_date = NULL;
$instruction_week = 0;

$parsed = split_course($q);
$subject_code = $parsed["subject"];
$course_num = $parsed["number"];

//If no semester is given, pick the latest one
$pick_last_semester = false;
if (is_null($_GET["semester"])) {
    $pick_last_semester = true;
}

//Get the start date of the given semester
$semesters_retval = get_semesters_before_date($dbh, date("Y-m-d"), $subject_code, $course_num);

//Check whether the course was offered at all
$not_offered = false;
if ($semesters_retval->rowCount() == 0) {
    $not_offered = true;
} else {

    while ($semester_row = $semesters_retval->fetch()) {

        $curr_sem = $semester_row["semester"];
        $curr_start_date = $semester_row["date"];
        $curr_instruction_date = $semester_row["instructiondate"];

        array_push($semesters_offered, $curr_sem);

        if ($curr_sem == $sem || $pick_last_semester) {
            $sem = $curr_sem;
            $start_date = $curr_start_date;
            $instruction_date = $curr_instruction_date;
        }
    }

    //Put the most recent semesters first
    $semesters_offered = array_reverse($semesters_offered);

    //If the semester given is not valid, pick the most recent one
    if (is_null($start_date)) {
        $sem = $semester_row["semester"];
        $start_date = $semester_row["date"];
        $instruction_date = $semester_row["instructiondate"];
    }

    //Calculate which week instruction begins in
    $instruction_week = floor(date_diff(new DateTime($instruction_date),
                                        new DateTime($start_date))->days/7);
}

?>

<div class="container">
    <div class="jumbotron">
        <?php include "../templates/search.php" ?>

<?php if (!is_null($_GET["q"])): ?>
        <br>
        <p>
        Semester:

<?php

//Print the semester links
foreach ($semesters_offered as $curr_sem) {
    if ($curr_sem == $sem) {
        echo "<b>$curr_sem</b> ";
    } else {
        echo "<a href='?q=$q&semester=$curr_sem'>$curr_sem</a> ";
    }
}

if ($not_offered) {
    echo "<b>No semesters on record</b>";
}

?>
        </p>
        <div id="chart-container"></div>

<script>

//Hide the "no data" message temporarily
Highcharts.setOptions({
    lang: {
        noData: ""
    }
});

//Dummy chart for initial loading message
$("#chart-container").highcharts({
    title: {
        text: ""
    }
})

$("#chart-container").highcharts().showLoading();

</script>


<?php

$series = array();
$series_list = array();

$enrollment_retval = query_semester($dbh, $sem, $start_date, NULL, "everything",
                        $subject_code, $course_num, true);

while ($enrollment_row = $enrollment_retval->fetch()) {

    $week = $enrollment_row["week"];
    $type = $enrollment_row["type"];
    $status = $enrollment_row["status"];
    $count = $enrollment_row["count"];

    $series[$type][$week] += $count;
}

$last_week = get_last_week($dbh, $sem, $start_date)["week"];

//Fill in empty weeks with zeroes and cut off the last week
foreach ($series as $type => $data) {
    unset($series[$type][$last_week]);
    for ($i  = 0; $i < $last_week; $i++) {
        if (!array_key_exists($i, $data)) {
            $series[$type][$i] = 0;
        }
    }
    
    ksort($series[$type]);

    $row = ["name" => $type, "data" => $series[$type]];
    array_push($series_list, $row);
}


$chart_title = $subject_code." ".$course_num;
if (is_null($subject_code) && is_null($course_num)) {
    $chart_title = "University of Illinois";
}

if (is_null($instruction_week)) {
    $instruction_week = "undefined";
}

if (is_null($last_week)) {
    $last_week = "undefined";
}

?>


<script>
/**
 * Returns whether the viewport is small or extra small.
 *
 * @return     {boolean}  True if the screen is small or extra small, else false
 */
function isSmallScreen() {
    return $(".device-sm").is(":visible");
}

//Hide the "no data" message temporarily
Highcharts.setOptions({
    lang: {
        noData: "No data for the given class"
    }
});

$(function() {
    //Actual chart
    $("#chart-container").highcharts({
        chart: {
            type: "spline"
        },
        title: {
            text: "<?php echo $chart_title ?>"
        },
        subtitle: {
            text: "<?php echo $sem ?>"
        },
        legend: {
            layout: (isSmallScreen() ? "horizontal" : "vertical"),
            align: (isSmallScreen() ? "center" : "right"),
            verticalAlign: (isSmallScreen() ? "bottom" : "middle"),
            floating: false,
            borderWidth: 1,
        },
        xAxis: {
            title: {
                text: "Week of registration"
            },
            allowDecimals: false,
            plotBands: [{
                from: <?= $instruction_week ?>,
                to: <?= $last_week ?>,
                color: "rgba(68, 170, 213, 0.2)",
                label: {
                    text: "Classes in session"
                }
            }]
        },
        yAxis: {
            title: {
                text: "Number of available sections"
            },
            allowDecimals: false
        },
        tooltip: {
            shared: true,
            valueSuffix: " sections"
        },
        credits: {
            enabled: false
        },
        plotOptions: {
            areaspline: {
                fillOpacity: 0.5
            },
            series: {
                marker: {
                    enabled: false
                }
            }
        },
        series: <?php echo json_encode($series_list) ?>
    });
});

</script>

<?php endif ?>
    </div>
</div>

<div class="device-sm visible-sm-block visible-xs-block"></div>

<?php include "../templates/footer.php"; ?>