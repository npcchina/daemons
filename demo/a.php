<?php

$to_url = 'https://mall.jd.com/index-1000002586.html';
$url_host = parse_url(strtolower($to_url),PHP_URL_HOST);
$flag = false;
if($url_host){
    $shost = explode('.',$url_host);
}
else{
    $to_true = explode('/',strtolower($to_url));
    $url_host = $to_true[0];
    $shost = explode('.',$to_true[0]);
}
$scount = count($shost);
$check_arr = explode(',','jd.com');
if(in_array($url_host,$check_arr)){
    $flag = true;
}
else if($scount >= 2){
    $check_domain = $shost[$scount-2].'.'.$shost[$scount-1];
    var_dump($check_domain);
    if(in_array($check_domain,$check_arr)){
        $flag = true;
    }
    else if($scount >= 3){
        $check_domain = $shost[$scount-3].'.'.$shost[$scount-2].'.'.$shost[$scount-1];
        var_dump($check_domain);
        if(in_array($check_domain,$check_arr)){
            $flag = true;
        }
        else{
            $check_domain = $shost[$scount-3].'.'.$shost[$scount-2];
            var_dump($check_domain);
            if(in_array($check_domain,$check_arr)){
                $flag = true;
            }
        }
    }
}

var_dump($flag);