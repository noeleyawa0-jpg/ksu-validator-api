<?php
require_once __DIR__.'/../api/year_standing.php';
$cases=[0=>1,24900=>1,24999=>1,25000=>2,51000=>2,51001=>3,74999=>3,75000=>4,100000=>4];
foreach ($cases as $earned=>$expected) {
    $r=[];
    for($year=1;$year<=4;$year++) foreach([1,2] as $sem) {
        $code="$year-$sem";
        $units=$year===1 ? ($sem===1 ? $earned : 100000-$earned) : 0;
        $r[]=['year'=>$year,'semester'=>$sem,'creditKey'=>$code,'codes'=>[$code=>true],'valid'=>true,'unitsTenths'=>$units];
    }
    $p=[];
    foreach([1=>'firstYear',2=>'secondYear',3=>'thirdYear',4=>'fourthYear'] as $n=>$key) $p[$key]=standing_progress($r,['1-1'=>true],4,$n);
    if(classified_year($p)!==$expected) throw new RuntimeException("Incorrect standing at $earned / 100000");
    if(count(array_filter($p,fn($rule)=>$rule['eligible']))!==1) throw new RuntimeException('Overlapping or missing year band.');
}
echo "PASS all four exact year bands, including values that round onto a boundary in the display.\n";
