<?php

namespace Tinycdn;

use Exception;

class CdnApi
{
    /**
     * Chunk size for loading large files on a CDN in several iterations
     */
    const CHUNK_SIZE = 524288; // 512k

    const FILE_TYPES = [
        'file',
        'image',
    ];

    const API_ALL_COLUMNS_FRAGMENT = '
        fragment fragmentCdnFileFull on CdnFile {
            cdn_file_id
            created_at
            size
            views
            site_id
            create_token
            is_public
            access_token
            filename
            is_uploaded
            type
            rule_key
            md5
            sha256
            meta
        }
    ';

    public $api_token = null;

    public $api_url = 'https://cdn.tinycdn.cloud/api/';

    public function __construct(string $api_token)
    {
        $this->api_token = $api_token;
        return $this;
    }

    public function setApiUrl(string $api_url)
    {
        $this->api_url = $api_url;
        return $this;
    }

    public function getApiToken() : ?string
    {
        return $this->api_token;
    }

    public function getApiUrl() : string
    {
        return $this->api_url;
    }

    public function getList(array $args = []) : array
    {
        $args = Api::encodeArguments($args);
        $query = "{ cdnFile($args) { ...fragmentCdnFileFull } }" . self::API_ALL_COLUMNS_FRAGMENT;
        $response = Api::rawRequest($query, $this->api_url, $this->api_token);
        
        return $response['data']['cdnFile'];
    }

    public function getFileInfo(int $id) : ?array
    {
        $list = $this->getList(['file_id' => $id]);
        return (empty($list)) ? null : $list[0];
    }

    /**
     * Upload file to CDN
     * @param  string $path   Path to file (http(s) link is allowed)
     * @param  array  $params assoc array (supported keys: type, is_public, rule_key)
     * @return array  File data
     */
    public function upload(string $path, array $params = []) : array
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
    
            $realpath = tempnam(sys_get_temp_dir(), 'tinycdn_download_');
            $handle = fopen($realpath, 'w');
            fwrite($handle, @file_get_contents($path));
            fclose($handle);

        } else {

            if (!file_exists($path) || !is_file($path)) {
                throw new Exception('File not found');
            }
            if (!is_readable($path)) {
                throw new Exception('File not readable');
            }

            $realpath = $path;
        }

        $size = filesize($realpath);
        if ($size === 0) {
            throw new Exception('File is empty');
        }
        
        $md5 = md5_file($realpath);
        $sha256 = (function_exists('hash_file')) ? hash_file('sha256', $realpath) : null;

        $filename = basename($path);
        $filename = (strpos($filename, '?') !== false) ? substr($filename, 0, strpos($filename, '?')) : $filename;

        if (!empty($params['filename'])) {
            $filename = $params['filename'];
        }

        $data = $this->addFile($filename, $md5, $sha256, $size, $params);
        if ($data['is_uploaded']) {
            return $data;
        }

        $id = $this->sendFileToCdn($realpath, $data['cdn_file_id'], $data['create_token']);
        return $this->getFileInfo($id);
    }

    /**
     * Delete a file from a CDN
     * @param  integer $cdn_file_id File ID on CDN
     * @return boolean Successful execution
     */
    public function delete(int $cdn_file_id) : bool
    {
        $file = self::getFileInfo($cdn_file_id);
        if ($file === null) {
            throw new Exception('File not found');
        }

        $args = [
            'file_id'      => $file['cdn_file_id'],
            'create_token' => $file['create_token'],
        ];

        $args = Api::encodeArguments($args);
        $response = Api::rawRequest(
            "mutation { cdnDeleteFile($args) }",
            $this->api_url,
            $this->api_token
        );

        return $response['data']['cdnDeleteFile'];
    }

    protected function addFile(
        string $filename,
        string $md5,
        ?string $sha256,
        int $size,
        array $params
    ) : array
    {
        $is_public = (isset($params['is_public'])) ? boolval($params['is_public']) : true;
        $file_type = 'file';
        if (isset($params['type']) && in_array($params['type'], self::FILE_TYPES)) {
            $file_type = $params['type'];
        }

        $args = [
            'filename'  => $filename,
            'md5'       => $md5,
            'size'      => $size,
            'is_public' => $is_public,
            'file_type' => $file_type,
        ];

        if (!empty($sha256)) {
            $args['sha256'] = $sha256;
        }
        if (!empty($params['rule_key'])) {
            $args['rule_key'] = $params['rule_key'] . '';
        }

        $args = Api::encodeArguments($args);
        $response = Api::rawRequest(
            "mutation { cdnAddFile($args) { ...fragmentCdnFileFull } }" . self::API_ALL_COLUMNS_FRAGMENT,
            $this->api_url,
            $this->api_token
        );

        return $response['data']['cdnAddFile'];
    }

    /**
     * Upload file directly to CDN
     * @param  string  $path         Full path to file
     * @param  integer $cdn_file_id  File ID on CDN
     * @param  string  $create_token File upload token
     * @return integer               File ID on CDN
     */
    protected function sendFileToCdn(string $path, int $cdn_file_id, string $create_token) : int
    {
        $args = [
            'file_id'      => $cdn_file_id,
            'create_token' => $create_token,
        ];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }

        while (!feof($handle)) {

            $buffer = fread($handle, self::CHUNK_SIZE);

            if (function_exists('gzcompress')) {
                $args['gzcompress'] = true;
                $args['content_base64'] = base64_encode(gzcompress($buffer, 9));
            } else {
                $args['content_base64'] = base64_encode($buffer);
            }

            $tmp = Api::encodeArguments($args);
            $query = "mutation { cdnUploadFile($tmp) { cdn_file_id, create_token, access_token } }";
            $response = Api::rawRequest($query, $this->api_url, $this->api_token);
        }

        fclose($handle);

        return $response['data']['cdnUploadFile']['cdn_file_id'] ?? 0;
    }
}
