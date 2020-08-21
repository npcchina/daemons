<?php
namespace Npc\Helper;

interface UrlParserInterface
{
    public static function getCouponInfo();

    public static function isCouponUrl();

    public static function isProductionUrl();
}