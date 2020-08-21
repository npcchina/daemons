<?php
namespace Npc\Helper\Jd;

use GuzzleHttp\Client;
use Npc\Helper\UrlParser as Parser;

class UrlParser implements \Npc\Helper\UrlParserInterface
{
    public static $url;

    public static $hosts = [
        Parser::PAGE_PRODUCT => [
                'item.jd.com'
        ],
        Parser::PAGE_LIST => [
        ],
        Parser::PAGE_COUPON => [
            'coupon.m.jd.com',
        ],
    ];

    function __construct($url = '')
    {
        self::$url = $url;

        //解析链接类型
    }

    public static function isCouponUrl()
    {
        return preg_match('#'.self::$hosts[Parser::PAGE_COUPON].'#is',self::$url);
    }

    public static function isProductionUrl()
    {
        return preg_match('#'.self::$hosts[Parser::PAGE_PRODUCT].'#is',self::$url);
    }

    public static function getCouponInfo()
    {
        //如果是优惠券链接
        if(self::isCouponUrl())
        {
            return self::getCouponInfoByUrl(self::$url);
        }
        //否则根据商品查优惠券
        else if(self::isProductionUrl())
        {

        }

        return [];
    }

    public static function getCouponInfoByKey($key = '' , $roleId = '')
    {
        return self::getCouponInfoByUrl('https://coupon.m.jd.com/coupons/show.action?key='.$key.'&roleId='.$roleId);
    }

    /**
     * @param string $url
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function getCouponInfoByUrl($url = '')
    {
        $client = new Client();
        //正则匹配
        preg_match_all('#<script>var _couponData =(.*?)</script>#is',$client->get($url)->getBody(),$matches);

        if($matches[1][0])
        {
            return json_decode(trim($matches[1][0]),true);
        }
        return [];
    }


}