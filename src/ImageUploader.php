<?php

/**
 * @author Ali Irani <ali@irani.im>
 */
class ImageUploader
{
    public $post;
    public $url;
    public $alt;

    public function __construct($url, $alt, $post)
    {
        $this->post = $post;
        $this->url = $url;
        $this->alt = $alt;
    }

    /**
     * Return host of url
     * @param null|string $url
     * @param bool $scheme
     * @param bool $www
     * @return null|string
     */
    public static function getHostUrl($url = null, $scheme = false, $www = false)
    {
        $url = $url ?: WpAutoUpload::getOption('base_url');

        $urlParts = parse_url($url);

        if (array_key_exists('host', $urlParts) === false) {
            return null;
        }

        $host = array_key_exists('port', $urlParts) ? $urlParts['host'] . ":" . $urlParts['port'] : $urlParts['host'];
        if (!$www) {
            $withoutWww = preg_split('/^(www(2|3)?\.)/i', $host, -1, PREG_SPLIT_NO_EMPTY); // Delete www from host
            $host = is_array($withoutWww) && array_key_exists(0, $withoutWww) ? $withoutWww[0] : $host;
        }
        return $scheme && array_key_exists('scheme', $urlParts) ? $urlParts['scheme'] . '://' . $host : $host;
    }

    /**
     * Check url is allowed to upload or not
     * @return bool
     */
    public function validate()
    {
        $url = self::getHostUrl($this->url);
        $site_url = self::getHostUrl() === null ? self::getHostUrl(site_url('url')) : self::getHostUrl();

        if ($url === $site_url || !$url) {
            return false;
        }

        if ($urls = WpAutoUpload::getOption('exclude_urls')) {
            $exclude_urls = explode("\n", $urls);

            foreach ($exclude_urls as $exclude_url) {
                if ($url === self::getHostUrl(trim($exclude_url))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Return custom image filename with user rules
     * @return string
     */
    protected function getFilename()
    {
        $filename = trim($this->resolvePattern(WpAutoUpload::getOption('image_name', '%filename%')));
        return sanitize_file_name($filename ?: uniqid('img_', false));
    }

    /**
     * Returns original image filename if valid
     * @return string|null
     */
    protected function getOriginalFilename()
    {
        $urlParts = pathinfo($this->url);

        if (!isset($urlParts['filename'])) {
            return null;
        }

        return sanitize_file_name($urlParts['filename']);
    }

    private $_uploadDir;

    /**
     * Return information of upload directory
     * fields: path, url, subdir, basedir, baseurl
     * @param $field
     * @return string|null
     */
    protected function getUploadDir($field)
    {
        if ($this->_uploadDir === null) {
            $this->_uploadDir = wp_upload_dir(date('Y/m', time()));
        }
        return is_array($this->_uploadDir) && array_key_exists($field, $this->_uploadDir) ? $this->_uploadDir[$field] : null;
    }

    /**
     * Return custom alt name with user rules
     * @return string Custom alt name
     */
    public function getAlt()
    {
        return $this->resolvePattern(WpAutoUpload::getOption('alt_name'));
    }

    /**
     * Returns string patterned
     * @param $pattern
     * @return string
     */
    public function resolvePattern($pattern)
    {
        preg_match_all('/%[^%]*%/', $pattern, $rules);

        $patterns = array(
            '%filename%' => $this->getOriginalFilename(),
            '%image_alt%' => $this->alt,
            '%date%' => date('Y-m-j'), // deprecated
            '%today_date%' => date('Y-m-j'),
            '%year%' => date('Y'),
            '%month%' => date('m'),
            '%day%' => date('j'), // deprecated
            '%today_day%' => date('j'),
            '%post_date%' => date('Y-m-j', strtotime($this->post['post_date_gmt'])),
            '%post_year%' => date('Y', strtotime($this->post['post_date_gmt'])),
            '%post_month%' => date('m', strtotime($this->post['post_date_gmt'])),
            '%post_day%' => date('j', strtotime($this->post['post_date_gmt'])),
            '%url%' => self::getHostUrl(get_bloginfo('url')),
            '%random%' => uniqid('img_', false),
            '%timestamp%' => time(),
            '%post_id%' => $this->post['ID'],
            '%postname%' => $this->post['post_name'],
            '%product_tag%' => $this->getWooCommerceProductTag(),
            '%product_title%' => $this->getWooCommerceProductTitle(),
        );

        if ($rules[0]) {
            foreach ($rules[0] as $rule) {
                $pattern = preg_replace("/$rule/", array_key_exists($rule, $patterns) ? $patterns[$rule] : $rule, $pattern);
            }
        }

        return $pattern;
    }

    /**
     * Save image and validate
     * @return null|array image data
     */
    public function save()
    {
        if (!$this->validate()) {
            return null;
        }

        $image = $this->downloadImage($this->url);

        if (is_wp_error($image)) {
            return null;
        }

        return $image;
    }

    /**
     * Download image
     * @param $url
     * @return array|WP_Error
     */
    public function downloadImage($url)
    {
        $url = self::normalizeUrl($url);
        $args = [
            'user-agent' => ''
        ];
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['host'])){
            $args['headers']['host'] = $parsedUrl['host'];
        }
        $response = wp_remote_get($url, $args);

        if ($response instanceof WP_Error) {
            return $response;
        }

        if (isset($response['response']['code'], $response['body']) && $response['response']['code'] !== 200) {
            return new WP_Error('aui_download_failed', 'AUI: Image file bad response.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'WP_AUI');
        file_put_contents($tempFile, $response['body']);
        $mime = wp_get_image_mime($tempFile);
        unlink($tempFile);

        if ($mime === false || strpos($mime, 'image/') !== 0) {
            return new WP_Error('aui_invalid_file', 'AUI: File type is not image.');
        }

        $image = [];
        $image['mime_type'] = $mime;
        $image['ext'] = self::getExtension($mime);
        $image['filename'] = $this->getFilename() . '.' . $image['ext'];
        $image['base_path'] = rtrim($this->getUploadDir('path'), DIRECTORY_SEPARATOR);
        $image['base_url'] = rtrim($this->getUploadDir('url'), '/');
        $image['path'] = $image['base_path'] . DIRECTORY_SEPARATOR . $image['filename'];
        $image['url'] = self::normalizeUrl($image['base_url'] . '/' . $image['filename']);
        $c = 1;

        $sameFileExists = false;
        while (is_file($image['path'])) {
            if (sha1($response['body']) === sha1_file($image['path'])) {
                $sameFileExists = true;
                break;
            }

            $image['path'] = $image['base_path'] . DIRECTORY_SEPARATOR . $c . '_' . $image['filename'];
            $image['url'] = self::normalizeUrl($image['base_url'] . '/' . $c . '_' . $image['filename']);
            $c++;
        }

        if ($sameFileExists) {
            return $image;
        }

        file_put_contents($image['path'], $response['body']);

        if (!is_file($image['path'])) {
            return new WP_Error('aui_image_save_failed', 'AUI: Image save to upload dir failed.');
        }

        $this->attachImage($image);

        if ($this->isNeedToResize() && ($resized = $this->resizeImage($image))) {
            $image['url'] = $resized['url'];
            $image['path'] = $resized['path'];
            $this->attachImage($image);
        }

        return $image;
    }

    /**
     * Attach image to post and media management
     * @param array $image
     * @return bool|int
     */
    public function attachImage($image)
    {
        $attachment = array(
            'guid' => $image['url'],
            'post_mime_type' => $image['mime_type'],
            'post_title' => $this->alt ?: preg_replace('/\.[^.]+$/', '', $image['filename']),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        $attach_id = wp_insert_attachment($attachment, $image['path'], $this->post['ID']);
        if (!function_exists('wp_generate_attachment_metadata')) {
            include_once( ABSPATH . 'wp-admin/includes/image.php' );
        }
        $attach_data = wp_generate_attachment_metadata($attach_id, $image['path']);

        return wp_update_attachment_metadata($attach_id, $attach_data);
    }

    /**
     * Resize image and returns resized url
     * @param $image
     * @return false|array
     */
    public function resizeImage($image)
    {
        $width = WpAutoUpload::getOption('max_width');
        $height = WpAutoUpload::getOption('max_height');
        $image_resized = image_make_intermediate_size($image['path'], $width, $height);

        if (!$image_resized) {
            return false;
        }

        return array(
            'url' => $image['base_url'] . '/' . urldecode($image_resized['file']),
            'path' => $image['base_path'] . DIRECTORY_SEPARATOR . urldecode($image_resized['file']),
        );
    }

    /**
     * Check image need to resize or not
     * @return bool
     */
    public function isNeedToResize()
    {
        return WpAutoUpload::getOption('max_width') || WpAutoUpload::getOption('max_height');
    }

    /**
     * Returns Image file extension by mime type
     * @param $mime
     * @return string|null
     */
    public static function getExtension($mime)
    {
        $mimes = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/bmp'  => 'bmp',
            'image/tiff' => 'tif',
            'image/webp' => 'webp',
        );

        return array_key_exists($mime, $mimes) ? $mimes[$mime] : null;
    }

    /**
     * @param $url
     * @return string
     */
    public static function normalizeUrl($url)
    {
        // 1. 清理 URL 前后的空白
        $url = trim($url);

        // 2. 处理以 // 开头的协议相对 URL（如 //example.com/image.jpg）
        if (substr($url, 0, 2) === '//') {
            // 不添加 https: 前缀，保持原样返回
            return $url;
        }

        // 3. 处理多重协议问题 (例如 https://https://example.com 或 https:https://example.com)
        if (preg_match('/(https?:\/\/|https?:)+/i', $url)) {
            // 提取域名和路径部分（去除所有协议前缀）
            $parts = preg_split('/(https?:\/\/|https?:)+/i', $url, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($parts)) {
                // 使用 https:// 作为默认协议
                $url = 'https://' . end($parts);
            }
        }

        // 4. 修复缺少斜杠的情况，如 https:example.com
        if (preg_match('/^https?:[^\/]/i', $url) && !preg_match('/^https?:\/\//', $url)) {
            $url = preg_replace('/^(https?:)/i', '$1//', $url);
        }

        return $url;
    }
    
    /**
     * 获取WooCommerce产品标签
     * @return string 产品标签，如果没有则返回空字符串
     */
    protected function getWooCommerceProductTag()
    {
        // 检查是否是WooCommerce产品
        if (!function_exists('wc_get_product') || $this->post['post_type'] !== 'product') {
            return '';
        }
        
        $product = wc_get_product($this->post['ID']);
        if (!$product) {
            return '';
        }
        
        // 获取产品标签
        $tags = wp_get_post_terms($this->post['ID'], 'product_tag');
        if (empty($tags) || is_wp_error($tags)) {
            return '';
        }
        
        // 返回第一个标签名称
        return sanitize_file_name($tags[0]->name);
    }
    
    /**
     * 获取WooCommerce产品标题
     * @return string 产品标题，如果没有则返回空字符串
     */
    protected function getWooCommerceProductTitle()
    {
        // 检查是否是WooCommerce产品
        if (!function_exists('wc_get_product') || $this->post['post_type'] !== 'product') {
            return '';
        }
        
        $product = wc_get_product($this->post['ID']);
        if (!$product) {
            return '';
        }
        
        // 返回产品标题
        return $product->get_title();
    }
}
