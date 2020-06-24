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
    ];
    $data2 = $cdn->upload('https://images.local/image.jpg', $params);
    var_dump($data2);



    //////////////////
    // File aliases //
    //////////////////

    // create custom URL for file
    // After this your file also will be accessed by URL http(s)://cdn.tinycdn.cloud/my/custorm/url/file.jpg
    // Many aliases per file, but url must be unique
    $alias = $cdn->addAlias($file_id, '/my/custorm/url/file.jpg');
    var_dump($alias);

    // remove Alias
    $cdn->deleteAlias($alias_id);

    // get Aliases list
    $args = [
        // 'file_id' => 10,            // File ID
        // 'id'      => 15,            // Alias id
        // 'url'     => '/alias-url/', // Alias URL
    ];
    $list = $cdn->getAliasesList(); // all
    $list2 = $cdn->getAliasesList($args); // by filter

} catch (Exception $e) {
    echo $e->getMessage();
}

```
