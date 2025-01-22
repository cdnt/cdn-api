# TinyCDN API

This package work with GraphQL API of [TinyCDN Service](https://tinycdn.cloud) 

More info you can find on [TinyCDN Service](https://tinycdn.cloud/docs/)

## Install

```
composer require "tinycdn/cdn-api"
```

## Usage

```php
<?php

$cdn = new \Tinycdn\CdnApi('your-api-token');

try {
    
    ////////////
    // Upload //
    ////////////

    // simple upload
    $data1 = $cdn->upload('path/to/file.ext');
    var_dump($data1);

    // more options
    $params = [
        'is_public' => false,        // default true
        'type'      => 'image',      // default file
        'filename'  => 'my-img.jpg', // if not set, filename will be detected automatically
        'rule_key'  => 'my-rule',    // see docs for details
        'folder_id' => 555,          // 0 - root folder. See more about folders
    ];
    $data2 = $cdn->upload('https://images.local/image.jpg', $params);
    var_dump($data2);

    /////////////
    // Folders //
    /////////////

    $filters = [
        'title' => 'Avatars',
        'idp'   => 0,
    ];
    $list = $cdn->getFoldersList($filters);
    $folder = $list[0] ?? $cdn->addFolder('Avatars');

    var_dump($folder);
    
} catch (Exception $e) {
    echo $e->getMessage();
}

```
