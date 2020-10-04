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

    const ALIAS_ALL_COLUMNS_FRAGMENT = '
        fragment fragmentCdnFileAliasFull on CdnFileAlias {
            id
            file_id
            created_at
            views
            url
        }
    ';

    const FILE_ALL_COLUMNS_FRAGMENT = '
        fragment fragmentCdnFileFull on CdnFile {
            cdn_file_id
            folder_id
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

    const FOLDER_ALL_COLUMNS_FRAGMENT = '
        fragment fragmentCdnFolderFull on CdnFolder {
            id
            idp
            site_id
            title
            created_at
            updated_at
            count_files
            count_folders
            create_token
            access_token
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

    public function getList(array $args = [], int $from = 0, int $limit = 100) : array
    {
        $args['from'] = $from;
        $args['limit'] = $limit;
        $args = Api::encodeArguments($args);

        $query = "{ cdnFile($args) { ...fragmentCdnFileFull } }" . self::FILE_ALL_COLUMNS_FRAGMENT;
        $response = Api::rawRequest($query, $this->api_url, $this->api_token);
        
        return $response['data']['cdnFile'];
    }

    public function getCount(array $args = []) : int
    {
        $args = Api::encodeArguments($args);
        $response = Api::rawRequest("{ cdnFileCount($args) }", $this->api_url, $this->api_token);
        
        return $response['data']['cdnFileCount'];
    }

    /**
     * Get File by ID
     * @param  integer $id File ID
     * @return ?array File data
     */
    public function getFileInfo(int $id) : ?array
    {
        $list = $this->getList(['file_id' => $id]);
        return $list[0] ?? null;
    }

    /**
     * Upload file to CDN
     * @param  string $path   Path to file (http(s) link is allowed)
     * @param  array  $params assoc array (supported keys: type, is_public, rule_key, folder_id)
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

    /**
     * Add file alias
     * @param integer $file_id File ID on CDN
     * @param string  $url     Alias URL
     * @return array  Alias data
     */
    public function addAlias(int $file_id, string $url) : array
    {
        $file = $this->getFileInfo($file_id);
        if ($file === null) {
            throw new Exception('File not found');
        }

        $args = [
            'file_id'      => $file_id,
            'create_token' => $file['create_token'],
            'url'          => $url,
        ];
        $args = Api::encodeArguments($args);

        $query = "mutation { cdnAddFileAlias($args) { ...fragmentCdnFileAliasFull } }" . self::ALIAS_ALL_COLUMNS_FRAGMENT;
        $response = Api::rawRequest($query, $this->api_url, $this->api_token);

        return $response['data']['cdnAddFileAlias'];
    }

    /**
     * Get list of aliases
     * @param  array $args assoc array with filters
     *                     possible keys:
     *                         file_id (int) - File ID
     *                         id (int) - Alias id
     *                         url (string) = Alias URL
     *                     . OPTIONAL
     * @param  integer $from  Offset for list (for pagination). OPTIONAL
     * @param  integer $limit Max count items in result (for pagination). OPTIONAL
     * @return array List of aliases
     */
    public function getAliasesList(array $args = [], int $from = 0, int $limit = 100) : array
    {
        $args['from'] = $from;
        $args['limit'] = $limit;
        $args = Api::encodeArguments($args);
        
        $query = "{ cdnFileAliases($args) { ...fragmentCdnFileAliasFull } }" . self::ALIAS_ALL_COLUMNS_FRAGMENT;
        $response = Api::rawRequest($query, $this->api_url, $this->api_token);
        
        return $response['data']['cdnFileAliases'];
    }

    /**
     * Get Alias by ID
     * @param  integer $id Alias ID
     * @return ?array Alias data
     */
    public function getAlias(int $id) : ?array
    {
        $list = $this->getAliasesList(['id' => $id]);
        return $list[0] ?? null;
    }

    /**
     * Delete File alias
     * @param  int    $alias_id File alias ID
     * @return boolean
     */
    public function deleteAlias(int $alias_id) : bool
    {
        $alias = $this->getAlias($alias_id);
        if ($alias === null) {
            throw new Exception('Alias not found');
        }

        $file = $this->getFileInfo($alias['file_id']);
        if ($file === null) {
            throw new Exception('File not found');
        }

        $args = [
            'id'           => $alias_id,
            'create_token' => $file['create_token'],
        ];
        $args = Api::encodeArguments($args);
        $response = Api::rawRequest("mutation { cdnDeleteFileAlias($args) }", $this->api_url, $this->api_token);

        return $response['data']['cdnDeleteFileAlias'];
    }

    /**
     * Add folder
     * @param string  $title Folder name
     * @param integer $idp   Parent folder ID. 0 - root folder. OPTIONAL
     * @return array  Folder data
     */
    public function addFolder(string $title, int $idp = 0) : array
    {
        $args = [
            'title' => $title,
            'idp'   => $idp,
        ];
        $args = Api::encodeArguments($args);

        $query = "mutation { cdnAddFolder($args) { ...fragmentCdnFolderFull } }" . self::FOLDER_ALL_COLUMNS_FRAGMENT;
        $response = Api::rawRequest($query, $this->api_url, $this->api_token);

        return $response['data']['cdnAddFolder'];
    }

    /**
     * Get list of aliases
     * @param  array $args Assoc array with filters
     *                     possible keys:
     *                         id (int) - Folder id
     *                         idp (int) - Parent folder ID
     *                         count_files (int) Count files in folder
     *                         count_folders (int) Count folders in folder
     *                         title (string) Folder title
     *                     . OPTIONAL
     * @param  integer $from  Offset for list (for pagination). OPTIONAL
     * @param  integer $limit Max count items in result (for pagination). OPTIONAL
     * @return array List of aliases
     */
    public function getFoldersList(array $args = [], int $from = 0, int $limit = 100) : array
    {
        $args['from'] = $from;
        $args['limit'] = $limit;
        $args = Api::encodeArguments($args);

        $query = "{ cdnFolder($args) { ...fragmentCdnFolderFull } }" . self::FOLDER_ALL_COLUMNS_FRAGMENT;
        $response = Api::rawRequest($query, $this->api_url, $this->api_token);
        
        return $response['data']['cdnFolder'];
    }

    /**
     * Get count of folders by filter
     * @param  array $args Assoc array with filters
     *                     possible keys:
     *                         id (int) - Folder id
     *                         idp (int) - Parent folder ID
     *                         count_files (int) Count files in folder
     *                         count_folders (int) Count folders in folder
     *                         title (string) Folder title
     *                     . OPTIONAL
     * @return integer Count folders
     */
    public function getFoldersCount(array $args = []) : int
    {
        $args = Api::encodeArguments($args);
        $response = Api::rawRequest("{ cdnFolderCount($args) }", $this->api_url, $this->api_token);
        
        return $response['data']['cdnFolderCount'];
    }

    /**
     * Get Folder by ID
     * @param  integer $id Folder ID
     * @return ?array Folder data
     */
    public function getFolder(int $id) : ?array
    {
        $list = $this->getFoldersList(['id' => $id]);
        return $list[0] ?? null;
    }

    /**
     * Update folder by ID
     * @param  array $data        Assoc array with new data
     *                            possible keys:
     *                                idp (int) - Parent folder ID
     *                                title (string) Folder title
     * @param  integer $folder_id Folder ID
     * @return ?array Folder data
     */
    public function updateFolder(array $data, int $folder_id) : ?array
    {
        $data['id'] = $folder_id;
        $args = Api::encodeArguments($data);
        
        $query = "mutation { cdnEditFolder($args) { ...fragmentCdnFolderFull } }" . self::FOLDER_ALL_COLUMNS_FRAGMENT;
        $response = Api::rawRequest($query, $this->api_url, $this->api_token);
        
        return $response['data']['cdnEditFolder'];
    }

    /**
     * Delete folder
     * @param integer $id           Folder ID. OPTIONAL
     * @param string  $create_token Folder create_token. OPTIONAL
     * @return void
     */
    public function deleteFolder(int $id, string $create_token = '') : void
    {
        if (empty($create_token)) {
            $folder = $this->getFolder($id);
            $create_token = $folder['create_token'] ?? '';
        }

        $args = [
            'id'           => $id,
            'create_token' => $create_token,
        ];
        $args = Api::encodeArguments($args);

        $query = "mutation { cdnDeleteFolder($args) }";
        Api::rawRequest($query, $this->api_url, $this->api_token);
    }

    protected function addFile(
        string $filename,
        string $md5,
        ?string $sha256,
        int $size,
        array $params
    ) : array
    {
        $is_public = boolval($params['is_public'] ?? true);
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
        if (!empty($params['folder_id'])) {
            $args['folder_id'] = round($params['folder_id']);
        }

        $args = Api::encodeArguments($args);
        $response = Api::rawRequest(
            "mutation { cdnAddFile($args) { ...fragmentCdnFileFull } }" . self::FILE_ALL_COLUMNS_FRAGMENT,
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
