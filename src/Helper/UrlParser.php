<?php
namespace Npc\Helper;

use \Exception;

class UrlParser
{
    const PAGE_PRODUCT = 1;
    const PAGE_LIST = 2;
    const PAGE_COUPON = 3;

    protected static $parsers = [
        'jd.com' => 'Jd',
    ];

    public function __construct()
    {
    }

    /**
     * @param string $url
     * @return \Npc\Helper\UrlParserInterface
     * @throws Exception
     */
    public static function parse($url = '')
    {
        $parse = parse_url($url);

        $match = $parse['host'];
        //匹配 domain
        while(($pos = stripos($match,'.')) !== false)
        {
            if(isset(self::$parsers[$match]))
            {
                break;
            }
            $match = substr($match,$pos + 1);
        }

        if(isset(self::$parsers[$match])) {
            $class = 'Npc\Helper\\' . self::$parsers[$match] . '\UrlParser';
            return new $class($url);
        }
        throw new Exception('invalid url');
    }
}