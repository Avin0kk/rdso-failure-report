<!DOCTYPE html>
<html>
<head>
    <title>Loco Failure Report</title>
    <style>
        body { font-family: Arial; margin: 30px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: center; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>

<h2>Loco Failure Report</h2>

<form method="POST">
    <label>Loco Type:</label>
    <select name="loco_type" required>
        <option value="">-- Select Loco Type --</option>
        <option value="2">ALCO</option>
        <option value="1">HHP</option>
        <option value="4">WABTECH</option>
    </select>

    <label>Financial Year:</label>
    <select name="fy" required>
        <option value="">-- Select Financial Year --</option>
        <option value="2021-22">2021-22</option>
        <option value="2020-21">2020-21</option>
        <option value="2019-20">2019-20</option>
    </select>

    <button type="submit">Generate</button>
</form>

<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $loco_cat = intval($_POST["loco_type"]);
    $fy = $_POST["fy"];

    $loco_names = [
        1 => 'HHP',
        2 => 'ALCO',
        4 => 'WABTECH'
    ];
    $loco_name = $loco_names[$loco_cat] ?? 'UNKNOWN';

    if ($fy == "2021-22") {
        $start = "2021-04-01";
        $end   = "2022-04-01";
    } elseif ($fy == "2020-21") {
        $start = "2020-04-01";
        $end   = "2021-04-01";
    } else {
        $start = "2019-04-01";
        $end   = "2020-04-01";
    }

    echo "<h2>$loco_name Failure Report</h2>";

    // DB connection
    $conn = pg_connect("
        host=localhost
        port=5432
        dbname=RDSO2
        user=postgres
        password=avin
    ");

    if (!$conn) {
        echo "<b>Database connection failed</b>";
        exit;
    }

    $query = "
WITH alco_main AS (
    SELECT DISTINCT ala.item_id AS main_item_id
    FROM public.tb_assembly_loco_assoc ala
    WHERE ala.loco_cat_id = $loco_cat
),

assembly_structure AS (
    SELECT
        m.item_id   AS main_item_id,
        m.item_name AS main_assembly,
        s.item_id   AS sub_item_id,
        s.item_name AS sub_assembly
    FROM alco_main am
    JOIN public.tb_item m
        ON m.item_id = am.main_item_id
    LEFT JOIN public.tb_item s
        ON s.parent_item = m.item_id
),

alco_failures AS (
    SELECT DISTINCT
        f.lf_id,
        f.doffail,
        f.subassembly AS sub_item_id
    FROM public.tb_loco_failure f
    JOIN public.tb_loco_master lm
        ON f.loconum = lm.loco_number
    WHERE lm.loco_catofloco = $loco_cat
      AND f.doffail >= '$start'
      AND f.doffail <  '$end'
),

prev_years AS (
    SELECT
        CASE
            WHEN f.doffail >= DATE '2020-04-01'
             AND f.doffail <  DATE '2021-04-01' THEN 'PY1'
            WHEN f.doffail >= DATE '2019-04-01'
             AND f.doffail <  DATE '2020-04-01' THEN 'PY2'
        END AS yr,
        f.subassembly AS sub_item_id,
        COUNT(DISTINCT f.lf_id) AS cases
    FROM public.tb_loco_failure f
    JOIN public.tb_loco_master lm
        ON f.loconum = lm.loco_number
    WHERE lm.loco_catofloco = $loco_cat
      AND f.doffail >= DATE '2019-04-01'
      AND f.doffail <  DATE '2021-04-01'
    GROUP BY yr, f.subassembly
),

rows AS (
    SELECT
        'FY $fy' AS financial_year,
        ast.main_assembly AS assembly,
        'MAIN' AS row_type,
        ast.main_item_id AS parent_sort,
        1 AS sort_order,
        af.lf_id,
        af.doffail,
        NULL::int AS prev_1_cases,
        NULL::int AS prev_2_cases
    FROM assembly_structure ast
    LEFT JOIN alco_failures af
        ON af.sub_item_id = ast.sub_item_id

    UNION ALL

    SELECT
        'FY $fy',
        ast.sub_assembly,
        'SUB',
        ast.main_item_id,
        2,
        af.lf_id,
        af.doffail,
        py1.cases,
        py2.cases
    FROM assembly_structure ast
    LEFT JOIN alco_failures af
        ON af.sub_item_id = ast.sub_item_id
    LEFT JOIN prev_years py1
        ON py1.yr = 'PY1'
       AND py1.sub_item_id = ast.sub_item_id
    LEFT JOIN prev_years py2
        ON py2.yr = 'PY2'
       AND py2.sub_item_id = ast.sub_item_id
    WHERE ast.sub_assembly IS NOT NULL
)

SELECT
    financial_year,
    assembly,
    row_type,

    CASE
        WHEN row_type = 'MAIN'
        THEN COALESCE(SUM(prev_2_cases) OVER (PARTITION BY parent_sort),0)
        ELSE COALESCE(prev_2_cases,0)
    END AS \"FY 2019-20\",

    CASE
        WHEN row_type = 'MAIN'
        THEN COALESCE(SUM(prev_1_cases) OVER (PARTITION BY parent_sort),0)
        ELSE COALESCE(prev_1_cases,0)
    END AS \"FY 2020-21\",

    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=4)  AS apr,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=5)  AS may,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=6)  AS jun,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=7)  AS jul,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=8)  AS aug,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=9)  AS sep,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=10) AS oct,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=11) AS nov,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=12) AS dec,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=1)  AS jan,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=2)  AS feb,
    COUNT(DISTINCT lf_id) FILTER (WHERE EXTRACT(MONTH FROM doffail)=3)  AS mar,

    COUNT(DISTINCT lf_id) AS total_cases
FROM rows
GROUP BY
    financial_year,
    assembly,
    row_type,
    parent_sort,
    sort_order,
    prev_1_cases,
    prev_2_cases
ORDER BY
    parent_sort,
    sort_order,
    assembly;
";

    $result = pg_query($conn, $query);

    if (!$result) {
        echo "<pre>".pg_last_error($conn)."</pre>";
        exit;
    }

    echo "<h3>Report for FY $fy</h3>";
    echo "<table><tr>";
    for ($i = 0; $i < pg_num_fields($result); $i++) {
        echo "<th>" . pg_field_name($result, $i) . "</th>";
    }
    echo "</tr>";

    while ($row = pg_fetch_assoc($result)) {
        echo "<tr>";
        foreach ($row as $val) {
            echo "<td>" . ($val ?? 0) . "</td>";
        }
        echo "</tr>";
    }

    echo "</table>";

    pg_close($conn);
}
?>

</body>
</html>
