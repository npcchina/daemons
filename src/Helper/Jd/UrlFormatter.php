<?php

namespace Npc\Helper\Jd;

class UrlFormatter
{

    public static function couponBatch($batchId = 0)
    {
        return [
            'https://so.m.jd.com/list/couponSearch.action?couponbatch='.$batchId,
            'https://search.jd.com/Search?coupon_batch='.$batchId
        ];
    }
}