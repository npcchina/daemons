<?php
require_once __DIR__ .'/../vendor/autoload.php';
use Npc\Helper\UrlParser;

$parser = UrlParser::parse('https://coupon.m.jd.com/coupons/show.action?key=c9e0702b67ee4e94a7012dcbd15b3eeb&roleId=33051271&to=mall.jd.com/index-10099981.html');


var_dump($parser->getCouponInfo());
