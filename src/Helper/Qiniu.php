<?php
namespace Npc\Helper;

use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use Qiniu\Storage\BucketManager;

class Qiniu
{
    private $auth = null;
    private $uploadManager = null;
    private $storage = null;

    private $uploadBucket = null;
    private $uploadTokenExpire = null;
    private $uploadKey = null;
    private $localizedUrl = null;

    /**
     * Qiniu constructor.
     * @param array $config = array(
     *      'AccessKey' => '',
     *      'SecretKey' => '',
     *      'storage' => '',
     *      'uploadBucket' => '',
     *      'uploadTokenExpire' => '',
     *      'uploadKey' => '',
     *      'localizedUrl' => '',
     * );
     */
    public function __construct($config = array())
    {
        // 初始化签权对象
        $this->auth = new Auth($config['AccessKey'], $config['SecretKey']);
        $this->uploadManager = new UploadManager();

        isset($config['storage']) && $this->storage = $config['storage'];

        isset($config['uploadBucket']) && $this->uploadBucket = $config['uploadBucket'];
        isset($config['uploadTokenExpire']) && $this->uploadTokenExpire = $config['uploadTokenExpire'];
        isset($config['uploadKey']) && $this->uploadKey = $config['uploadKey'];
        isset($config['localizedUrl']) && $this->localizedUrl = $config['localizedUrl'];

    }

    /**
     * @param $storage
     */
    public function setStorage($storage)
    {
        $this->storage = $storage;
    }

    /**
     * 设置缓存
     *
     * @param $key
     * @param $data
     * @param int $lifetime
     * @return null
     */
    public function cacheSet($key, $data, $lifetime = 7200)
    {
        if ($this->storage) {
            return $this->storage->set($key, $data, $lifetime);
        } else {
            return null;
        }
    }

    /**
     * 获取缓存
     *
     * @param $key
     * @param $lifetime
     * @return null
     */
    public function cacheGet($key)
    {
        if ($this->storage) {
            return $this->storage->get($key);
        } else {
            return null;
        }
    }

    /**
     * 判断缓存是否存在
     *
     * @param $key
     * @param $lifetime
     * @return bool
     */
    public function cacheExists($key)
    {
        if ($this->storage) {
            return $this->storage->get($key);
        } else {
            return false;
        }
    }



    public function refreshUploadToken($bucket, $key = null, $expires = 0, $policy = null, $strictPolicy = true)
    {
        $bucket = $bucket ? $bucket : $this->uploadBucket;
        $expires = $expires ? $expires : $this->uploadTokenExpire;
        $key = $key ? $key : $this->uploadKey;
        $token = $this->auth->uploadToken($bucket,$key,$expires,$policy,$strictPolicy);

        $this->storage->set($bucket.'_'.$key.'_token',$token,$expires - 600);
        return $token;
    }

    /**
     * @param $bucket
     * @param string $key
     * @param int $expires
     * @param null $policy
     * @param bool|true $strictPolicy
     * @return string
     */
    public function getUploadToken($bucket = '', $key = null, $expires = 0, $policy = null, $strictPolicy = true)
    {
        $bucket = $bucket ? $bucket : $this->uploadBucket;
        $expires = $expires ? $expires : $this->uploadTokenExpire;
        $key = $key ? $key : $this->uploadKey;

        $token = $this->cacheGet($bucket.'_'.$key.'_token');
        return $token ?  $token : $this->refreshUploadToken($bucket, $key, $expires, $policy, $strictPolicy);
    }

    /**
     *  获取上传对象
     * @return \Qiniu\Storage\UploadManager
     */
    public function getUploadManager()
    {
        return $this->uploadManager;
    }

    /**
     *  获取图片信息
     * @return \Qiniu\Storage\BucketManager
     */
    public function getImageInfo($imageUrl)
    {
        $bucketMgr = new BucketManager($this->auth);
        //bug fix
        list($ret, $err) = $bucketMgr->stat($this->uploadBucket, str_replace($this->localizedUrl,'',$imageUrl));
        if ($err !== null) {
        } else {
            return $ret;
        }
    }
}